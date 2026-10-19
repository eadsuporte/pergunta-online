<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/layout.php';
require_once __DIR__ . '/api-chatgpt/historical.php';
if (!app_installed()) {
    redirect('install');
}
$admin = require_admin();

$q = trim((string)Request::get('q', Request::STRING, ''));
$roleFilter = (string)Request::get('role', Request::STRING, '');
$stateFilter = (string)Request::get('state', Request::STRING, '');
$page = max(1, (int)Request::get('page', Request::INT, 1));
$perPage = 50;

if (!in_array($roleFilter, ['', 'user', 'respondent', 'admin'], true)) {
    $roleFilter = '';
}
if (!in_array($stateFilter, ['', 'active', 'inactive'], true)) {
    $stateFilter = '';
}

$returnQuery = http_build_query(array_filter([
    'q' => $q,
    'role' => $roleFilter,
    'state' => $stateFilter,
    'page' => $page > 1 ? (string)$page : '',
], static fn(string $value): bool => $value !== ''));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $action = (string)Request::post('action', Request::STRING, '');

    if ($action === 'create') {
        $name = trim((string)Request::post('name', Request::STRING, ''));
        $email = normalize_email((string)Request::post('email', Request::STRING, ''));
        $password = (string)Request::post('password', Request::STRING, '');
        $role = (string)Request::post('role', Request::STRING, 'user');
        if (!in_array($role, ['user', 'respondent', 'admin'], true)) {
            $role = 'user';
        }
        if (strlen($name) < 2 || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($password) < 10) {
            flash('error', 'Informe nome, e-mail válido e senha com pelo menos 10 caracteres.');
        } else {
            try {
                $rawPerfil = trim((string)Request::post('perfil', Request::STRING, ''));
                $perfilValue = $rawPerfil === '' ? null : (json_decode($rawPerfil, true) ?? $rawPerfil);
                $perfil = api_perfil_encode($perfilValue);
                $rawDate = trim((string)Request::post('created_at', Request::STRING, ''));
                if (preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/', $rawDate)) {
                    $rawDate .= ':00';
                }
                $createdAt = api_historical_date($rawDate, 'created_at') ?? date('Y-m-d H:i:s');
                $stmt = db()->prepare('INSERT INTO users (name,email,password_hash,role,perfil,created_at,updated_at) VALUES (?,?,?,?,?,?,?)');
                $stmt->execute([$name, $email, password_hash($password, PASSWORD_DEFAULT), $role,
                    $perfil, $createdAt, $createdAt]);
                $newId = (int)db()->lastInsertId();
                audit_log((int)$admin['id'], 'user.created', 'user', $newId, $role);
                flash('success', 'Usuário criado.');
            } catch (PDOException $e) {
                flash('error', $e->getCode() === '23000' ? 'Já existe uma conta com esse e-mail.' : 'Não foi possível criar o usuário.');
            } catch (InvalidArgumentException $e) {
                flash('error', $e->getMessage());
            }
        }
        redirect('admin/users');
    }

    $id = (int)Request::post('user_id', Request::INT, 0);
    if ($id === (int)$admin['id']) {
        flash('warning', 'Você não pode remover o próprio acesso administrativo nesta tela.');
        redirect('admin/users' . ($returnQuery !== '' ? '?' . $returnQuery : ''));
    }

    $stmt = db()->prepare('SELECT id,role,active FROM users WHERE id=?');
    $stmt->execute([$id]);
    $target = $stmt->fetch();

    if ($target && $action === 'set_active') {
        $active = (bool)Request::post('active', Request::BOOL, false);
        $s = db()->prepare('UPDATE users SET active=? WHERE id=?');
        $s->execute([$active ? 1 : 0, $id]);
        audit_log((int)$admin['id'], 'user.active_changed', 'user', $id, $active ? 'active' : 'inactive');
        flash('success', $active ? 'Usuário ativado.' : 'Usuário desativado.');
    } elseif ($target && $action === 'set_role') {
        $role = (string)Request::post('role', Request::STRING, '');
        if (in_array($role, ['user', 'respondent', 'admin'], true)) {
            $s = db()->prepare('UPDATE users SET role=? WHERE id=?');
            $s->execute([$role, $id]);
            audit_log((int)$admin['id'], 'user.role_changed', 'user', $id, (string)$target['role'] . ' -> ' . $role);
            flash('success', 'Perfil do usuário atualizado.');
        }
    }
    redirect('admin/users' . ($returnQuery !== '' ? '?' . $returnQuery : ''));
}

$where = [];
$params = [];
if ($q !== '') {
    $where[] = '(name LIKE ? OR email LIKE ?)';
    $term = '%' . $q . '%';
    array_push($params, $term, $term);
}
if ($roleFilter !== '') {
    $where[] = 'role = ?';
    $params[] = $roleFilter;
}
if ($stateFilter === 'active') {
    $where[] = 'active = 1';
} elseif ($stateFilter === 'inactive') {
    $where[] = 'active = 0';
}

$whereSql = $where !== [] ? ' WHERE ' . implode(' AND ', $where) : '';
$countStmt = db()->prepare('SELECT COUNT(*) FROM users' . $whereSql);
$countStmt->execute($params);
$totalFiltered = (int)$countStmt->fetchColumn();
$totalPages = max(1, (int)ceil($totalFiltered / $perPage));
$page = min($page, $totalPages);
$offset = ($page - 1) * $perPage;

$sql = 'SELECT id,name,email,role,active,last_login_at,created_at,updated_at FROM users' . $whereSql .
    ' ORDER BY created_at DESC LIMIT ' . $perPage . ' OFFSET ' . $offset;
$stmt = db()->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();

$statsRow = db()->query("
    SELECT COUNT(*) total,
           SUM(active=1) active_count,
           SUM(active=0) inactive_count,
           SUM(role='admin') admin_count,
           SUM(role='respondent') respondent_count
    FROM users
")->fetch();

$summary = [
    ['label' => 'Usuários', 'value' => (int)$statsRow['total']],
    ['label' => 'Ativos', 'value' => (int)$statsRow['active_count']],
    ['label' => 'Respondentes', 'value' => (int)$statsRow['respondent_count']],
    ['label' => 'Administradores', 'value' => (int)$statsRow['admin_count']],
];

$users = array_map(static function(array $user) use ($admin): array {
    $isActive = (int)$user['active'] === 1;
    $role = (string)$user['role'];
    return [
        'id' => (int)$user['id'],
        'name' => (string)$user['name'],
        'email' => (string)$user['email'],
        'role_label' => match ($role) {
            'admin' => 'Administrador',
            'respondent' => 'Respondente',
            default => 'Usuário',
        },
        'role_class' => $role,
        'status' => $isActive ? 'Ativo' : 'Desativado',
        'status_class' => $isActive ? 'active' : 'inactive',
        'created_at' => date('d/m/Y', strtotime((string)$user['created_at'])),
        'updated_at' => date('d/m/Y H:i', strtotime((string)$user['updated_at'])),
        'last_login_at' => !empty($user['last_login_at']) ? date('d/m/Y H:i', strtotime((string)$user['last_login_at'])) : 'Nunca',
        'can_manage' => (int)$user['id'] !== (int)$admin['id'],
        'set_admin' => $role !== 'admin',
        'set_respondent' => $role !== 'respondent',
        'set_user' => $role !== 'user',
        'activate' => !$isActive,
        'detail_url' => base_url('admin/user?id=' . (int)$user['id']),
    ];
}, $rows);

$roleFilters = [
    ['value' => '', 'label' => 'Todos os perfis', 'selected' => $roleFilter === ''],
    ['value' => 'user', 'label' => 'Usuários', 'selected' => $roleFilter === 'user'],
    ['value' => 'respondent', 'label' => 'Respondentes', 'selected' => $roleFilter === 'respondent'],
    ['value' => 'admin', 'label' => 'Administradores', 'selected' => $roleFilter === 'admin'],
];
$stateFilters = [
    ['value' => '', 'label' => 'Todos os status', 'selected' => $stateFilter === ''],
    ['value' => 'active', 'label' => 'Ativos', 'selected' => $stateFilter === 'active'],
    ['value' => 'inactive', 'label' => 'Desativados', 'selected' => $stateFilter === 'inactive'],
];

$queryBase = ['q' => $q, 'role' => $roleFilter, 'state' => $stateFilter];
$prevQuery = $queryBase;
$prevQuery['page'] = max(1, $page - 1);
$nextQuery = $queryBase;
$nextQuery['page'] = min($totalPages, $page + 1);

render_page('admin/users', [
    'users' => $users,
    'summary' => $summary,
    'result_count' => count($users),
    'total_filtered' => $totalFiltered,
    'search_query' => $q,
    'role_filters' => $roleFilters,
    'state_filters' => $stateFilters,
    'has_filters' => $q !== '' || $roleFilter !== '' || $stateFilter !== '',
    'has_prev' => $page > 1,
    'has_next' => $page < $totalPages,
    'prev_url' => base_url('admin/users?' . http_build_query($prevQuery)),
    'next_url' => base_url('admin/users?' . http_build_query($nextQuery)),
    'page' => $page,
    'total_pages' => $totalPages,
], 'Usuários', true);
