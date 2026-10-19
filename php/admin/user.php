<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/layout.php';
require_once __DIR__ . '/api-chatgpt/historical.php';
if (!app_installed()) {
    redirect('install');
}
$admin = require_admin();
$id = (int)Request::get('id', Request::INT, 0);

$stmt = db()->prepare('SELECT id,name,email,role,active,perfil,last_login_at,created_at,updated_at FROM users WHERE id=? LIMIT 1');
$stmt->execute([$id]);
$user = $stmt->fetch();
if (!$user) {
    http_response_code(404);
    render_page('not_found', [], 'Usuário não encontrado', true);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $action = (string)Request::post('action', Request::STRING, '');
    if ($action === 'save_perfil') {
        $rawPerfil = trim((string)Request::post('perfil', Request::STRING, ''));
        $perfilValue = $rawPerfil === '' ? null : (json_decode($rawPerfil, true) ?? $rawPerfil);
        try {
            api_user_profile_update(['user_id' => $id, 'perfil' => $perfilValue], $admin);
            flash('success', 'Perfil de escrita atualizado.');
        } catch (InvalidArgumentException $e) {
            flash('error', $e->getMessage());
        }
        redirect('admin/user?id=' . $id);
    }
    if ($action === 'reset_password') {
        $password = (string)Request::post('password', Request::STRING, '');
        $confirm = (string)Request::post('confirm_password', Request::STRING, '');
        if (strlen($password) < 10) {
            flash('error', 'A nova senha precisa ter pelo menos 10 caracteres.');
        } elseif (!hash_equals($password, $confirm)) {
            flash('error', 'As senhas não conferem.');
        } else {
            $update = db()->prepare('UPDATE users SET password_hash=? WHERE id=?');
            $update->execute([password_hash($password, PASSWORD_DEFAULT), $id]);
            audit_log((int)$admin['id'], 'user.password_reset', 'user', $id);
            flash('success', 'Senha redefinida.');
        }
        redirect('admin/user?id=' . $id);
    }
}

$countQuestions = db()->prepare('SELECT COUNT(*) FROM questions WHERE user_id=? AND deleted_at IS NULL');
$countQuestions->execute([$id]);
$countVotes = db()->prepare('SELECT COUNT(*) FROM question_votes WHERE user_id=?');
$countVotes->execute([$id]);
$countSaves = db()->prepare('SELECT COUNT(*) FROM question_saves WHERE user_id=?');
$countSaves->execute([$id]);

$questionsStmt = db()->prepare("
    SELECT id,title,status,published,created_at
    FROM questions
    WHERE user_id=? AND deleted_at IS NULL
    ORDER BY created_at DESC
    LIMIT 20
");
$questionsStmt->execute([$id]);
$questions = array_map(static fn(array $q): array => [
    'title' => (string)$q['title'],
    'status' => status_label((string)$q['status']),
    'visibility' => (int)$q['published'] === 1 ? 'Pública' : 'Oculta',
    'created_at' => date('d/m/Y H:i', strtotime((string)$q['created_at'])),
    'url' => base_url('admin/question?id=' . (int)$q['id']),
], $questionsStmt->fetchAll());

$targetsStmt = db()->prepare("
    SELECT t.name
    FROM target_users tu
    JOIN targets t ON t.id=tu.target_id
    WHERE tu.user_id=?
    ORDER BY t.name
");
$targetsStmt->execute([$id]);
$targets = array_map(static fn(string $name): array => ['name' => $name], $targetsStmt->fetchAll(PDO::FETCH_COLUMN));

$auditStmt = db()->prepare("
    SELECT action,details,created_at
    FROM admin_audit_log
    WHERE entity_type='user' AND entity_id=?
    ORDER BY created_at DESC
    LIMIT 20
");
$auditStmt->execute([$id]);
$audit = array_map(static fn(array $row): array => [
    'action' => (string)$row['action'],
    'details' => (string)($row['details'] ?? ''),
    'created_at' => date('d/m/Y H:i', strtotime((string)$row['created_at'])),
], $auditStmt->fetchAll());

render_page('admin/user', [
    'user' => [
        'id' => (int)$user['id'],
        'name' => (string)$user['name'],
        'email' => (string)$user['email'],
        'perfil_json' => $user['perfil'] === null ? '' :
            (json_encode(api_perfil_decode((string)$user['perfil']),
                JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: ''),
        'role' => match ((string)$user['role']) {
            'admin' => 'Administrador',
            'respondent' => 'Respondente',
            default => 'Usuário',
        },
        'status' => (int)$user['active'] === 1 ? 'Ativo' : 'Desativado',
        'created_at' => date('d/m/Y H:i', strtotime((string)$user['created_at'])),
        'updated_at' => date('d/m/Y H:i', strtotime((string)$user['updated_at'])),
        'last_login_at' => !empty($user['last_login_at']) ? date('d/m/Y H:i', strtotime((string)$user['last_login_at'])) : 'Nunca',
    ],
    'stats' => [
        ['label' => 'Perguntas', 'value' => (int)$countQuestions->fetchColumn()],
        ['label' => 'Endossos', 'value' => (int)$countVotes->fetchColumn()],
        ['label' => 'Salvas', 'value' => (int)$countSaves->fetchColumn()],
    ],
    'questions' => $questions,
    'has_questions' => $questions !== [],
    'targets' => $targets,
    'has_targets' => $targets !== [],
    'audit' => $audit,
    'has_audit' => $audit !== [],
    'back_url' => base_url('admin/users'),
], 'Usuário', true);
