<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

const CHATGPT_API_TOKEN_HASH = '2d550b0a5fc3a4a4ff8ff62f94c4e25f3d7c1b345dedfad65cb8123928366a37';

function api_json(array $data, int $status = 200): never {
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    exit;
}

function api_error(string $message, int $status = 400, array $details = []): never {
    api_json([
        'ok' => false,
        'error' => $message,
        'details' => $details,
    ], $status);
}

function api_method(array $allowed): void {
    $method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    if (!in_array($method, $allowed, true)) {
        header('Allow: ' . implode(', ', $allowed));
        api_error('Método HTTP não permitido.', 405, ['allowed' => $allowed]);
    }
}

function api_payload(): array {
    $length = (int)($_SERVER['CONTENT_LENGTH'] ?? 0);
    if ($length > 1024 * 1024) {
        api_error('Payload acima do limite de 1 MB.', 413);
    }

    $raw = file_get_contents('php://input');
    if ($raw === false || trim($raw) === '') {
        api_error('Envie um corpo JSON.', 400);
    }

    try {
        $data = json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
    } catch (JsonException $e) {
        api_error('JSON inválido.', 400, ['json_error' => $e->getMessage()]);
    }

    if (!is_array($data)) {
        api_error('O JSON precisa ser um objeto ou uma lista de objetos.', 400);
    }

    return $data;
}

function api_payload_items(): array {
    $payload = api_payload();
    if (array_is_list($payload)) {
        $items = $payload;
    } elseif (isset($payload['items'])) {
        if (!is_array($payload['items']) || !array_is_list($payload['items'])) {
            api_error('O campo items precisa ser uma lista.', 422);
        }
        $items = $payload['items'];
    } else {
        $items = [$payload];
    }

    if ($items === [] || count($items) > 50) {
        api_error('Envie entre 1 e 50 itens por requisição.', 422);
    }
    foreach ($items as $item) {
        if (!is_array($item) || array_is_list($item)) {
            api_error('Cada item precisa ser um objeto JSON.', 422);
        }
    }

    return $items;
}

function api_bearer_token(): string {
    $header = trim((string)($_SERVER['HTTP_AUTHORIZATION'] ?? ''));
    if ($header === '' && function_exists('getallheaders')) {
        $headers = getallheaders();
        if (is_array($headers)) {
            foreach ($headers as $name => $value) {
                if (strcasecmp((string)$name, 'Authorization') === 0) {
                    $header = trim((string)$value);
                    break;
                }
            }
        }
    }

    if (preg_match('/^Bearer\s+(.+)$/i', $header, $matches)) {
        return trim($matches[1]);
    }

    return trim((string)($_SERVER['HTTP_X_API_KEY'] ?? ''));
}

function api_require_auth(): array {
    global $appconfig;

    if (!app_installed()) {
        api_error('A aplicação ainda não foi instalada.', 503);
    }

    $config = is_array($appconfig['chatgpt_api'] ?? null) ? $appconfig['chatgpt_api'] : [];

    $provided = api_bearer_token();
    $configured = trim((string)(getenv("CHATGPT_API_TOKEN") ?: ($config["token"] ?? "")));
    // An environment/config token overrides the historical token entirely.
    // Deploy a new secret to revoke the token exposed by older public documentation.
    if ($configured !== "") {
        $authenticated = $provided !== "" && hash_equals($configured, $provided);
    } else {
        $authenticated = $provided !== "" && hash_equals(CHATGPT_API_TOKEN_HASH, hash("sha256", $provided));
    }
    if (!$authenticated) {
        api_error("Token de API inválido.", 401);
    }

    $actorEmail = normalize_email((string)(getenv('CHATGPT_API_ACTOR_EMAIL') ?: ($config['actor_email'] ?? '')));
    if ($actorEmail !== '') {
        $stmt = db()->prepare("SELECT id,name,email,role,active FROM users WHERE email=? AND role='admin' AND active=1 LIMIT 1");
        $stmt->execute([$actorEmail]);
    } else {
        $stmt = db()->query("SELECT id,name,email,role,active FROM users WHERE role='admin' AND active=1 ORDER BY id LIMIT 1");
    }
    $actor = $stmt->fetch();
    if (!$actor) {
        api_error('Nenhum administrador ativo foi encontrado para registrar as importações.', 503);
    }

    return $actor;
}

function api_string(mixed $value, string $field, int $min = 0, int $max = 0): string {
    if (!is_scalar($value) && $value !== null) {
        throw new InvalidArgumentException("O campo {$field} precisa ser texto.");
    }
    $value = trim((string)$value);
    if ($min > 0 && strlen($value) < $min) {
        throw new InvalidArgumentException("O campo {$field} precisa ter ao menos {$min} caracteres.");
    }
    if ($max > 0 && strlen($value) > $max) {
        throw new InvalidArgumentException("O campo {$field} aceita no máximo {$max} caracteres.");
    }
    return $value;
}

function api_bool(mixed $value, bool $default = true): bool {
    if ($value === null) {
        return $default;
    }
    if (is_bool($value)) {
        return $value;
    }
    if (is_int($value)) {
        return $value === 1;
    }
    $normalized = strtolower(trim((string)$value));
    if (in_array($normalized, ['1', 'true', 'yes', 'sim', 'on'], true)) {
        return true;
    }
    if (in_array($normalized, ['0', 'false', 'no', 'nao', 'não', 'off'], true)) {
        return false;
    }
    return $default;
}

function api_root_url(): string {
    global $appconfig;

    $configured = rtrim((string)($appconfig['app_url'] ?? ''), '/');
    if ($configured !== '') {
        return $configured;
    }

    $host = trim((string)($_SERVER['HTTP_HOST'] ?? ''));
    if ($host === '') {
        return '';
    }
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $script = str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? ''));
    $rootPath = preg_replace('~/admin/api-chatgpt(?:/[^/]*)?$~', '', $script) ?? '';
    $rootPath = preg_replace('~\.php$~', '', $rootPath) ?? $rootPath;
    return $scheme . '://' . $host . rtrim($rootPath, '/');
}

function api_focus_upsert(array|string $input, array $actor): array {
    $data = is_array($input) ? $input : ['name' => $input];
    $name = api_string($data['name'] ?? '', 'focus.name', 2, 120);

    $stmt = db()->prepare('SELECT id,name,abbr,active FROM focuses WHERE name=? LIMIT 1');
    $stmt->execute([$name]);
    $row = $stmt->fetch();
    if ($row) {
        $abbrValue = $data['abbr'] ?? ($data['abbreviation'] ?? null);
        $abbr = $abbrValue === null
            ? (string)$row['abbr']
            : strtoupper(api_string($abbrValue, 'focus.abbr', 1, 12));
        $active = array_key_exists('active', $data)
            ? api_bool($data['active'], (int)$row['active'] === 1)
            : (int)$row['active'] === 1;

        $update = db()->prepare('UPDATE focuses SET abbr=?,active=? WHERE id=?');
        $update->execute([$abbr, $active ? 1 : 0, (int)$row['id']]);
        $sync = db()->prepare('UPDATE questions SET focus_name=?,focus_abbr=? WHERE focus_id=?');
        $sync->execute([$name, $abbr, (int)$row['id']]);
        audit_log((int)$actor['id'], 'api.focus.updated', 'focus', (int)$row['id'], $name);
        return ['id' => (int)$row['id'], 'name' => $name, 'abbr' => $abbr, 'active' => $active, 'created' => false];
    }

    $abbr = strtoupper(api_string($data['abbr'] ?? ($data['abbreviation'] ?? ''), 'focus.abbr', 1, 12));
    $active = api_bool($data['active'] ?? null, true);
    $insert = db()->prepare('INSERT INTO focuses (name,abbr,active) VALUES (?,?,?)');
    $insert->execute([$name, $abbr, $active ? 1 : 0]);
    $id = (int)db()->lastInsertId();
    audit_log((int)$actor['id'], 'api.focus.created', 'focus', $id, $name);
    return ['id' => $id, 'name' => $name, 'abbr' => $abbr, 'active' => $active, 'created' => true];
}

function api_category_upsert(array|string $input, array $actor): array {
    $data = is_array($input) ? $input : ['name' => $input];
    $name = api_string($data['name'] ?? '', 'category.name', 2, 120);

    $stmt = db()->prepare('SELECT id,name,active FROM categories WHERE name=? LIMIT 1');
    $stmt->execute([$name]);
    $row = $stmt->fetch();
    if ($row) {
        $active = array_key_exists('active', $data)
            ? api_bool($data['active'], (int)$row['active'] === 1)
            : (int)$row['active'] === 1;
        $update = db()->prepare('UPDATE categories SET active=? WHERE id=?');
        $update->execute([$active ? 1 : 0, (int)$row['id']]);
        audit_log((int)$actor['id'], 'api.category.updated', 'category', (int)$row['id'], $name);
        return ['id' => (int)$row['id'], 'name' => $name, 'active' => $active, 'created' => false];
    }

    $active = api_bool($data['active'] ?? null, true);
    $createdAt = api_historical_date($data['created_at'] ?? null, 'category.created_at')
        ?? date('Y-m-d H:i:s');
    $updatedAt = api_historical_date($data['updated_at'] ?? null, 'category.updated_at', $createdAt)
        ?? $createdAt;
    $insert = db()->prepare('INSERT INTO categories (name,active,created_at,updated_at) VALUES (?,?,?,?)');
    $insert->execute([$name, $active ? 1 : 0, $createdAt, $updatedAt]);
    $id = (int)db()->lastInsertId();
    audit_log((int)$actor['id'], 'api.category.created', 'category', $id, $name);
    return ['id' => $id, 'name' => $name, 'active' => $active, 'created' => true];
}

function api_target_upsert(array|string $input, array $actor): array {
    $data = is_array($input) ? $input : ['name' => $input];
    $name = api_string($data['name'] ?? '', 'target.name', 2, 190);

    $stmt = db()->prepare('SELECT id,name,description,website,contact_email,active FROM targets WHERE name=? LIMIT 1');
    $stmt->execute([$name]);
    $row = $stmt->fetch();

    $description = array_key_exists('description', $data)
        ? api_string($data['description'], 'target.description')
        : (string)($row['description'] ?? '');
    $website = array_key_exists('website', $data)
        ? api_string($data['website'], 'target.website', 0, 500)
        : (string)($row['website'] ?? '');
    $email = array_key_exists('contact_email', $data)
        ? normalize_email(api_string($data['contact_email'], 'target.contact_email', 0, 190))
        : (string)($row['contact_email'] ?? '');
    $active = array_key_exists('active', $data)
        ? api_bool($data['active'], $row ? (int)$row['active'] === 1 : true)
        : ($row ? (int)$row['active'] === 1 : true);

    if ($website !== '' && !filter_var($website, FILTER_VALIDATE_URL)) {
        throw new InvalidArgumentException('target.website precisa ser uma URL válida.');
    }
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        throw new InvalidArgumentException('target.contact_email precisa ser um e-mail válido.');
    }

    if ($row) {
        $update = db()->prepare('UPDATE targets SET description=?,website=?,contact_email=?,active=? WHERE id=?');
        $update->execute([$description ?: null, $website ?: null, $email ?: null, $active ? 1 : 0, (int)$row['id']]);
        audit_log((int)$actor['id'], 'api.target.updated', 'target', (int)$row['id'], $name);
        return [
            'id' => (int)$row['id'], 'name' => $name, 'description' => $description,
            'website' => $website, 'contact_email' => $email, 'active' => $active, 'created' => false,
        ];
    }

    $insert = db()->prepare('INSERT INTO targets (name,description,website,contact_email,active) VALUES (?,?,?,?,?)');
    $insert->execute([$name, $description ?: null, $website ?: null, $email ?: null, $active ? 1 : 0]);
    $id = (int)db()->lastInsertId();
    audit_log((int)$actor['id'], 'api.target.created', 'target', $id, $name);
    return [
        'id' => $id, 'name' => $name, 'description' => $description,
        'website' => $website, 'contact_email' => $email, 'active' => $active, 'created' => true,
    ];
}

function api_evidence_add(int $questionId, array $input, array $actor): array {
    $title = api_string($input['title'] ?? '', 'evidence.title', 2, 190);
    $type = strtolower(api_string($input['type'] ?? 'url', 'evidence.type', 1, 20));
    $reference = api_string($input['url'] ?? ($input['reference'] ?? ($input['text'] ?? '')), 'evidence.reference');

    if (!in_array($type, ['url', 'text'], true)) {
        throw new InvalidArgumentException('A API aceita evidências do tipo url ou text. Arquivos continuam sendo enviados pela interface administrativa.');
    }
    if ($type === 'url' && !filter_var($reference, FILTER_VALIDATE_URL)) {
        throw new InvalidArgumentException('evidence.url precisa ser uma URL válida.');
    }
    if ($type === 'text' && $reference === '') {
        throw new InvalidArgumentException('evidence.text não pode ser vazio.');
    }

    $exists = db()->prepare('SELECT id FROM question_evidence WHERE question_id=? AND evidence_type=? AND title=? AND reference_value=? LIMIT 1');
    $exists->execute([$questionId, $type, $title, $reference]);
    $existingId = (int)($exists->fetchColumn() ?: 0);
    if ($existingId > 0) {
        return ['id' => $existingId, 'title' => $title, 'type' => $type, 'reference' => $reference, 'created' => false];
    }

    $question = db()->prepare('SELECT created_at FROM questions WHERE id=? AND deleted_at IS NULL');
    $question->execute([$questionId]);
    $questionCreated = $question->fetchColumn();
    if (!$questionCreated) {
        throw new InvalidArgumentException('Pergunta não encontrada.');
    }
    $createdAt = api_historical_date($input['created_at'] ?? null, 'evidence.created_at', (string)$questionCreated)
        ?? date('Y-m-d H:i:s');
    $evidenceUserId = isset($input['user_id']) ? (int)$input['user_id'] : (int)$actor['id'];
    if ($evidenceUserId < 1) {
        throw new InvalidArgumentException('evidence.user_id inválido.');
    }
    $stmt = db()->prepare('SELECT id FROM users WHERE id=? LIMIT 1');
    $stmt->execute([$evidenceUserId]);
    if (!$stmt->fetchColumn()) {
        throw new InvalidArgumentException('Usuário da evidência não encontrado.');
    }
    $stmt = db()->prepare('INSERT INTO question_evidence (question_id,user_id,evidence_type,title,reference_value,created_at) VALUES (?,?,?,?,?,?)');
    $stmt->execute([$questionId, $evidenceUserId, $type, $title, $reference, $createdAt]);
    $id = (int)db()->lastInsertId();
    if (isset($input['user_id'])) {
        api_touch_user_activity($evidenceUserId, $createdAt);
    }
    question_history_add($questionId, (int)$actor['id'], 'evidence_added', null, null, $title, $createdAt);
    audit_log((int)$actor['id'], 'api.question.evidence_added', 'question', $questionId, $title);

    return ['id' => $id, 'title' => $title, 'type' => $type, 'reference' => $reference, 'created_at' => $createdAt, 'created' => true];
}

function api_question_create(array $input, array $actor): array {
    $title = api_string($input['title'] ?? '', 'title', 15, 255);
    $body = api_string($input['body'] ?? ($input['context'] ?? ''), 'body', 20);

    if (!isset($input['focus'])) {
        throw new InvalidArgumentException('Informe focus com name e abbr.');
    }
    if (!isset($input['category'])) {
        throw new InvalidArgumentException('Informe category.');
    }
    if (!isset($input['target'])) {
        throw new InvalidArgumentException('Informe target.');
    }

    $focusInput = $input['focus'];
    if (is_string($focusInput)) {
        $focusInput = ['name' => $focusInput, 'abbr' => $input['focus_abbr'] ?? ''];
    }
    if (!is_array($focusInput)) {
        throw new InvalidArgumentException('focus precisa ser texto ou objeto.');
    }

    $categoryInput = $input['category'];
    if (!is_array($categoryInput) && !is_string($categoryInput)) {
        throw new InvalidArgumentException('category precisa ser texto ou objeto.');
    }
    $targetInput = $input['target'];
    if (!is_array($targetInput) && !is_string($targetInput)) {
        throw new InvalidArgumentException('target precisa ser texto ou objeto.');
    }

    $createdAt = api_historical_date($input['created_at'] ?? null, 'created_at') ?? date('Y-m-d H:i:s');
    $authorId = (int)($input['user_id'] ?? $actor['id']);
    $authorStmt = db()->prepare('SELECT id FROM users WHERE id=? LIMIT 1');
    $authorStmt->execute([$authorId]);
    if (!$authorStmt->fetchColumn()) {
        throw new InvalidArgumentException('user_id não corresponde a um usuário existente.');
    }

    $focus = api_focus_upsert($focusInput, $actor);
    $category = api_category_upsert($categoryInput, $actor);
    $target = api_target_upsert($targetInput, $actor);
    // Imported questions establish the same focus-to-target relationship as the admin UI.
    $linkTarget = db()->prepare('INSERT IGNORE INTO target_focuses (target_id,focus_id) VALUES (?,?)');
    $linkTarget->execute([(int)$target['id'], (int)$focus['id']]);

    $duplicate = db()->prepare('SELECT id,status,published,moderation_status FROM questions WHERE title=? AND target_id=? AND deleted_at IS NULL LIMIT 1');
    $duplicate->execute([$title, (int)$target['id']]);
    $existing = $duplicate->fetch();

    if ($existing) {
        $questionId = (int)$existing['id'];
        $created = false;
    } else {
        $status = strtolower(api_string($input['status'] ?? 'open', 'status', 1, 20));
        $allowedStatus = ['open', 'waiting', 'taken', 'answered', 'archived'];
        if (!in_array($status, $allowedStatus, true)) {
            throw new InvalidArgumentException('status inválido.');
        }

        $defaultPublish = app_setting('auto_publish_questions', '1') === '1';
        $published = api_bool($input['published'] ?? null, $defaultPublish);
        $moderation = strtolower(api_string(
            $input['moderation_status'] ?? ($published ? 'approved' : 'pending'),
            'moderation_status',
            1,
            20
        ));
        if (!in_array($moderation, ['pending', 'approved', 'rejected'], true)) {
            throw new InvalidArgumentException('moderation_status inválido.');
        }
        if ($moderation !== 'approved') {
            $published = false;
        }

        $answer = api_string($input['answer_text'] ?? '', 'answer_text');
        if ($status === 'answered' && $answer === '') {
            throw new InvalidArgumentException('answer_text é obrigatório quando status=answered.');
        }
        $answeredAt = $status === 'answered'
            ? (api_historical_date($input['answered_at'] ?? null, 'answered_at', $createdAt) ?? $createdAt)
            : null;
        if ($status !== 'answered' && array_key_exists('answered_at', $input)) {
            throw new InvalidArgumentException('answered_at exige status=answered.');
        }
        $updatedAt = api_historical_date($input['updated_at'] ?? null, 'updated_at', $createdAt)
            ?? ($answeredAt ?? $createdAt);
        if ($answeredAt !== null && $updatedAt < $answeredAt) {
            throw new InvalidArgumentException('updated_at não pode anteceder answered_at.');
        }
        $waitingSince = in_array($status, ['waiting', 'taken'], true)
            ? (api_historical_date($input['waiting_since'] ?? null, 'waiting_since', $createdAt) ?? $createdAt)
            : null;
        $takenAt = $status === 'taken'
            ? (api_historical_date($input['taken_at'] ?? null, 'taken_at', $createdAt) ?? $createdAt)
            : null;
        $answeredBy = null;
        $takenBy = null;
        if ($status === 'answered' || $status === 'taken') {
            $attributor = (int)($input[$status === 'answered' ? 'answered_by_user_id' : 'taken_by_user_id'] ?? $actor['id']);
            $stmtUser = db()->prepare("SELECT id FROM users WHERE id=? AND role IN ('admin','respondent') LIMIT 1");
            $stmtUser->execute([$attributor]);
            if (!$stmtUser->fetchColumn()) {
                throw new InvalidArgumentException('Respondente não encontrado ou sem papel permitido.');
            }
            if ($status === 'answered') {
                $answeredBy = $attributor;
            } else {
                $takenBy = $attributor;
            }
        }

        $stmt = db()->prepare("
            INSERT INTO questions
                (user_id,focus_id,focus_name,focus_abbr,category_id,category,title,body,target_name,target_id,
                 status,answer_text,waiting_since,answered_at,answered_by,taken_by,taken_at,
                 published,moderation_status,created_at,updated_at)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
        ");
        $stmt->execute([
            $authorId, (int)$focus['id'], $focus['name'], $focus['abbr'],
            (int)$category['id'], $category['name'], $title, $body,
            $target['name'], (int)$target['id'], $status, $answer ?: null,
            $waitingSince, $answeredAt, $answeredBy, $takenBy, $takenAt,
            $published ? 1 : 0, $moderation, $createdAt, $updatedAt,
        ]);
        $questionId = (int)db()->lastInsertId();
        $created = true;
        api_touch_user_activity($authorId, $createdAt);
        if ($answeredBy !== null) {
            api_touch_user_activity($answeredBy, (string)$answeredAt);
        } elseif ($takenBy !== null) {
            api_touch_user_activity($takenBy, (string)$takenAt);
        }
        question_history_add($questionId, (int)$actor['id'], 'created', null, $status, 'Cadastro histórico via API ChatGPT; autor=' . $authorId, $createdAt);
        audit_log((int)$actor['id'], 'api.question.created', 'question', $questionId, $title);
        $existing = ['status' => $status, 'published' => $published ? 1 : 0, 'moderation_status' => $moderation];
    }

    $evidence = [];
    $evidenceInput = $input['evidence'] ?? ($input['sources'] ?? []);
    if ($evidenceInput !== []) {
        if (!is_array($evidenceInput) || !array_is_list($evidenceInput)) {
            throw new InvalidArgumentException('evidence precisa ser uma lista.');
        }
        if (count($evidenceInput) > 20) {
            throw new InvalidArgumentException('Cada pergunta aceita no máximo 20 evidências por chamada.');
        }
        foreach ($evidenceInput as $item) {
            if (!is_array($item)) {
                throw new InvalidArgumentException('Cada evidência precisa ser um objeto.');
            }
            $evidence[] = api_evidence_add($questionId, $item, $actor);
        }
    }

    $root = api_root_url();
    return [
        'id' => $questionId,
        'created' => $created,
        'title' => $title,
        'user_id' => $created ? $authorId : (int)db()->query('SELECT user_id FROM questions WHERE id=' . $questionId)->fetchColumn(),
        'created_at' => $created ? $createdAt : (string)db()->query('SELECT created_at FROM questions WHERE id=' . $questionId)->fetchColumn(),
        'status' => (string)$existing['status'],
        'published' => (int)$existing['published'] === 1,
        'moderation_status' => (string)$existing['moderation_status'],
        'focus' => $focus,
        'category' => $category,
        'target' => $target,
        'evidence' => $evidence,
        'url' => $root !== '' ? $root . '/question?id=' . $questionId : '/question?id=' . $questionId,
    ];
}

function api_handle(callable $callback): never {
    try {
        $result = $callback();
        api_json(['ok' => true, 'data' => $result]);
    } catch (InvalidArgumentException $e) {
        if (db()->inTransaction()) {
            db()->rollBack();
        }
        api_error($e->getMessage(), 422);
    } catch (PDOException $e) {
        if (db()->inTransaction()) {
            db()->rollBack();
        }
        $status = $e->getCode() === '23000' ? 409 : 500;
        api_error($status === 409 ? 'Conflito com um registro existente.' : 'Erro de banco de dados.', $status);
    } catch (Throwable $e) {
        if (db()->inTransaction()) {
            db()->rollBack();
        }
        error_log('API ChatGPT: ' . $e->getMessage());
        api_error('Erro interno ao processar a API.', 500);
    }
}

require_once __DIR__ . '/historical.php';
