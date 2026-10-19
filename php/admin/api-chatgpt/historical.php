<?php

declare(strict_types=1);

/**
 * Metadata supplied by an administrator for historical imports.
 *
 * The imported date describes the original event. admin_audit_log continues
 * to record the real time and the authenticated administrator performing it.
 */
function api_historical_date(mixed $value, string $field, ?string $earliest = null): ?string {
    if ($value === null || $value === '') {
        return null;
    }
    if (!is_string($value)) {
        throw new InvalidArgumentException("{$field} deve ser uma data em YYYY-MM-DD HH:MM:SS.");
    }
    $value = trim(str_replace('T', ' ', $value));
    $date = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $value);
    if (!$date || $date->format('Y-m-d H:i:s') !== $value) {
        throw new InvalidArgumentException("{$field} deve ser uma data real em YYYY-MM-DD HH:MM:SS.");
    }
    if ($value > date('Y-m-d H:i:s')) {
        throw new InvalidArgumentException("{$field} não pode estar no futuro.");
    }
    if ($earliest !== null && $value < $earliest) {
        throw new InvalidArgumentException("{$field} não pode anteceder {$earliest}.");
    }
    return $value;
}

/**
 * Preserve rich writing characteristics as JSON in users.perfil LONGTEXT.
 * Examples: estilo_escrita, time_coracao, usa_emojis, emojis_frequentes,
 * abreviacoes, pontuacao, expressoes, exemplos_escrita and observacoes.
 */
function api_perfil_encode(mixed $value): ?string {
    if ($value === null) {
        return null;
    }
    if (is_string($value)) {
        $value = ['observacoes' => trim($value)];
    }
    if (!is_array($value) || ($value !== [] && array_is_list($value))) {
        throw new InvalidArgumentException('perfil deve ser um objeto JSON ou uma descrição textual.');
    }
    try {
        $json = json_encode($value === [] ? new stdClass() : $value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    } catch (JsonException $e) {
        throw new InvalidArgumentException('perfil contém dados que não podem ser serializados.', 0, $e);
    }
    if (strlen($json) > 32768) {
        throw new InvalidArgumentException('perfil deve ter no máximo 32 KB.');
    }
    return $json;
}

function api_perfil_decode(?string $value): ?array {
    if ($value === null || $value === '') {
        return null;
    }
    $decoded = json_decode($value, true);
    return is_array($decoded) ? $decoded : ['observacoes' => $value];
}

function api_user_create(array $input, array $actor): array {
    $name = api_string($input['name'] ?? '', 'name', 2, 120);
    $email = normalize_email(api_string($input['email'] ?? '', 'email', 3, 190));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        throw new InvalidArgumentException('email inválido.');
    }
    $role = api_string($input['role'] ?? 'user', 'role');
    // Administrative accounts are created through the existing admin UI only.
    if (!in_array($role, ['user', 'respondent'], true)) {
        throw new InvalidArgumentException('A API só cria contas user ou respondent.');
    }
    $createdAt = api_historical_date($input['created_at'] ?? null, 'created_at') ?? date('Y-m-d H:i:s');
    $updatedAt = api_historical_date($input['updated_at'] ?? null, 'updated_at', $createdAt) ?? $createdAt;
    $perfil = api_perfil_encode($input['perfil'] ?? null);
    $active = api_bool($input['active'] ?? null, true);

    $existing = db()->prepare('SELECT id,name,email,role,active,perfil,created_at,updated_at FROM users WHERE email=? LIMIT 1');
    $existing->execute([$email]);
    $row = $existing->fetch();
    if ($row) {
        // A repeated import must never silently rewrite a real account.
        return [
            'id' => (int)$row['id'], 'created' => false, 'name' => $row['name'],
            'email' => $row['email'], 'role' => $row['role'],
            'active' => (bool)$row['active'],
            'perfil' => api_perfil_decode($row['perfil']),
            'created_at' => $row['created_at'], 'updated_at' => $row['updated_at'],
        ];
    }

    $password = $input['password'] ?? null;
    if ($password !== null && (!is_string($password) || strlen($password) < 10)) {
        throw new InvalidArgumentException('password deve ter ao menos 10 caracteres.');
    }
    // No default, disclosed or predictable credential is generated.
    $password ??= bin2hex(random_bytes(32));
    $insert = db()->prepare(
        'INSERT INTO users (name,email,password_hash,role,active,perfil,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?)'
    );
    $insert->execute([
        $name, $email, password_hash($password, PASSWORD_DEFAULT),
        $role, $active ? 1 : 0, $perfil, $createdAt, $updatedAt,
    ]);
    $id = (int)db()->lastInsertId();
    audit_log((int)$actor['id'], 'api.user.created', 'user', $id, 'Historical created_at=' . $createdAt);
    return [
        'id' => $id, 'created' => true, 'name' => $name, 'email' => $email,
        'role' => $role, 'active' => $active, 'perfil' => api_perfil_decode($perfil),
        'created_at' => $createdAt, 'updated_at' => $updatedAt,
    ];
}

function api_users_search(string $query): array {
    if (strlen($query) > 190) {
        throw new InvalidArgumentException('query deve ter no máximo 190 caracteres.');
    }
    $stmt = db()->prepare(
        'SELECT id,name,email,role,active,perfil,created_at,updated_at FROM users
         WHERE name LIKE ? OR email LIKE ? ORDER BY id DESC LIMIT 30'
    );
    $like = '%' . trim($query) . '%';
    $stmt->execute([$like, $like]);
    return ['results' => array_map(static function (array $row): array {
        $row['id'] = (int)$row['id'];
        $row['active'] = (bool)$row['active'];
        $row['perfil'] = api_perfil_decode($row['perfil']);
        return $row;
    }, $stmt->fetchAll())];
}

function api_user_profile_update(array $input, array $actor): array {
    $userId = (int)($input['user_id'] ?? 0);
    if ($userId < 1 || !array_key_exists('perfil', $input)) {
        throw new InvalidArgumentException('Informe user_id e perfil.');
    }
    $stmt = db()->prepare('SELECT id,created_at FROM users WHERE id=? LIMIT 1');
    $stmt->execute([$userId]);
    $user = $stmt->fetch();
    if (!$user) {
        throw new InvalidArgumentException('Usuário não encontrado.');
    }
    $updatedAt = api_historical_date($input['updated_at'] ?? null, 'updated_at', $user['created_at'])
        ?? date('Y-m-d H:i:s');
    $perfil = api_perfil_encode($input['perfil']);
    $stmt = db()->prepare('UPDATE users SET perfil=?,updated_at=? WHERE id=?');
    $stmt->execute([$perfil, $updatedAt, $userId]);
    audit_log((int)$actor['id'], 'api.user.profile_updated', 'user', $userId, 'Historical updated_at=' . $updatedAt);
    return ['id' => $userId, 'perfil' => api_perfil_decode($perfil), 'updated_at' => $updatedAt];
}

function api_question_answer(array $input, array $actor): array {
    $questionId = (int)($input['question_id'] ?? 0);
    $answer = api_string($input['answer_text'] ?? '', 'answer_text', 1);
    if ($questionId < 1) {
        throw new InvalidArgumentException('question_id inválido.');
    }
    $pdo = db();
    $stmt = $pdo->prepare(
        'SELECT id,title,created_at,updated_at,status,answer_text,answered_at,answered_by
         FROM questions WHERE id=? AND deleted_at IS NULL FOR UPDATE'
    );
    $stmt->execute([$questionId]);
    $question = $stmt->fetch();
    if (!$question) {
        throw new InvalidArgumentException('Pergunta não encontrada.');
    }
    if ($question['status'] === 'answered' && trim((string)$question['answer_text']) !== '') {
        if (trim((string)$question['answer_text']) === $answer) {
            return [
                'question_id' => $questionId, 'changed' => false,
                'answered_by_user_id' => $question['answered_by'] !== null ? (int)$question['answered_by'] : null,
                'answered_at' => $question['answered_at'], 'status' => 'answered',
            ];
        }
        if (!api_bool($input['replace_existing'] ?? null, false)) {
            throw new InvalidArgumentException('Esta pergunta já possui uma resposta. Use replace_existing=true para substituí-la.');
        }
    }

    $respondentId = (int)($input['answered_by_user_id'] ?? $actor['id']);
    $r = $pdo->prepare('SELECT id,role FROM users WHERE id=? LIMIT 1');
    $r->execute([$respondentId]);
    $respondent = $r->fetch();
    if (!$respondent || !in_array($respondent['role'], ['respondent', 'admin'], true)) {
        throw new InvalidArgumentException('answered_by_user_id precisa identificar um respondente ou administrador.');
    }
    $answeredAt = api_historical_date($input['answered_at'] ?? null, 'answered_at', $question['created_at'])
        ?? date('Y-m-d H:i:s');
    $updatedAt = api_historical_date($input['updated_at'] ?? null, 'updated_at', $answeredAt)
        ?? max($answeredAt, (string)$question['updated_at']);
    $stmt = $pdo->prepare(
        "UPDATE questions SET answer_text=?,status='answered',answered_at=?,answered_by=?,updated_at=? WHERE id=?"
    );
    $stmt->execute([$answer, $answeredAt, $respondentId, $updatedAt, $questionId]);
    if ($question['status'] !== 'answered') {
        question_history_add($questionId, (int)$actor['id'], 'status_changed', $question['status'], 'answered', null, $answeredAt);
    }
    question_history_add(
        $questionId, (int)$actor['id'], 'answer_updated', $question['status'], 'answered',
        'Resposta atribuída ao usuário ' . $respondentId . ' por importação administrativa.', $answeredAt
    );
    audit_log((int)$actor['id'], 'api.question.answered', 'question', $questionId,
        'Respondent=' . $respondentId . '; historical answered_at=' . $answeredAt);
    return [
        'question_id' => $questionId, 'changed' => true,
        'answered_by_user_id' => $respondentId, 'answered_at' => $answeredAt,
        'updated_at' => $updatedAt, 'status' => 'answered',
    ];
}
