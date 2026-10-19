<?php

declare(strict_types=1);

require_once __DIR__ . '/request.php';
require_once __DIR__ . '/migrations.php';

const APP_NAME = 'Pergunta.Online';
const APP_VERSION = '1.8.0';

$configfile = dirname(__DIR__) . '/config.php';
if (!is_file($configfile)) {
    http_response_code(500);
    exit('Arquivo config.php não encontrado. Copie/configure php/config.php antes de continuar.');
}

$appconfig = require $configfile;
if (!is_array($appconfig)) {
    http_response_code(500);
    exit('O arquivo config.php precisa retornar um array de configuração.');
}

if (session_status() !== PHP_SESSION_ACTIVE) {
    $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
    session_set_cookie_params([
        'httponly' => true,
        'secure' => $secure,
        'samesite' => 'Lax',
        'path' => '/',
    ]);
    session_start();
}

function db(): PDO {
    static $pdo = null;
    global $appconfig;

    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $database = $appconfig['database'] ?? [];
    $host = (string)($database['host'] ?? '127.0.0.1');
    $port = (int)($database['port'] ?? 3306);
    $name = trim((string)($database['name'] ?? ''));
    $user = trim((string)($database['user'] ?? ''));
    $password = (string)($database['password'] ?? '');

    if ($name === '' || $user === '') {
        throw new RuntimeException('Configure database.name e database.user em config.php.');
    }

    $pdo = new PDO(
        "mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4",
        $user,
        $password,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]
    );
    run_schema_migrations($pdo);

    return $pdo;
}

function app_installed(): bool {
    try {
        $stmt = db()->query("SHOW TABLES LIKE 'users'");
        return (bool)$stmt->fetchColumn();
    } catch (Throwable $e) {
        return false;
    }
}

function base_url(string $path = ''): string {
    global $appconfig;

    $base = rtrim((string)($appconfig['app_url'] ?? ''), '/');
    if ($base !== '') {
        return $base . '/' . ltrim($path, '/');
    }

    $script = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '');
    $dir = preg_replace('~/(?:admin|blog)$~', '', dirname($script));
    $dir = $dir === '/' ? '' : rtrim($dir, '/');

    return $dir . '/' . ltrim($path, '/');
}

function h(?string $value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function csrf_token(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return (string)$_SESSION['csrf_token'];
}

function require_csrf(): void {
    $token = Request::post('csrf_token', Request::STRING, '');
    if (!hash_equals(csrf_token(), (string)$token)) {
        http_response_code(419);
        exit('Sessão expirada ou token CSRF inválido. Volte e tente novamente.');
    }
}

function redirect(string $path): never {
    header('Location: ' . base_url($path));
    exit;
}

function flash(string $type, string $message): void {
    $_SESSION['flash'][] = ['type' => $type, 'message' => $message];
}

function consume_flashes(): array {
    $items = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return is_array($items) ? $items : [];
}

function current_user(): ?array {
    static $loaded = false;
    static $user = null;

    if ($loaded) {
        return $user;
    }

    $loaded = true;
    $id = (int)($_SESSION['user_id'] ?? 0);
    if ($id <= 0 || !app_installed()) {
        return null;
    }

    $stmt = db()->prepare('SELECT id,name,email,role,active,created_at,last_login_at FROM users WHERE id=? LIMIT 1');
    $stmt->execute([$id]);
    $row = $stmt->fetch();

    if (!$row || !(int)$row['active']) {
        unset($_SESSION['user_id']);
        return null;
    }

    $user = $row;
    return $user;
}

function require_login(): array {
    $user = current_user();
    if (!$user) {
        flash('warning', 'Entre na sua conta para continuar.');
        redirect('login');
    }
    return $user;
}

function require_admin(): array {
    $user = require_login();
    if (($user['role'] ?? '') !== 'admin') {
        http_response_code(403);
        exit('Acesso restrito à administração.');
    }
    return $user;
}

function normalize_email(string $email): string {
    return strtolower(trim($email));
}

function client_ip(): string {
    return substr((string)($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'), 0, 45);
}

/**
 * Keep the latest known activity date, including events imported by an admin.
 * This is an activity timestamp and must not be treated as proof of login.
 */
function user_last_activity_touch(int $userId, string $eventAt): void {
    $stmt = db()->prepare(
        'UPDATE users SET
           last_login_at=CASE WHEN last_login_at IS NULL OR last_login_at<? THEN ? ELSE last_login_at END,
           updated_at=updated_at
         WHERE id=?'
    );
    $stmt->execute([$eventAt, $eventAt, $userId]);
}

function status_label(string $status): string {
    return match ($status) {
        'waiting' => 'Aguardando resposta',
        'answered' => 'Resposta registrada',
        'taken' => 'Pauta assumida',
        'archived' => 'Arquivada',
        default => 'Aberta à comunidade',
    };
}

function status_class(string $status): string {
    return in_array($status, ['waiting', 'answered', 'taken'], true) ? $status : '';
}

function app_setting(string $key, string $default = ''): string {
    static $cache = [];
    if (array_key_exists($key, $cache)) {
        return $cache[$key];
    }
    if (!app_installed()) {
        return $default;
    }
    $stmt = db()->prepare('SELECT setting_value FROM app_settings WHERE setting_key=? LIMIT 1');
    $stmt->execute([$key]);
    $value = $stmt->fetchColumn();
    $cache[$key] = $value === false ? $default : (string)$value;
    return $cache[$key];
}

function audit_log(?int $adminUserId, string $action, string $entityType, ?int $entityId = null, ?string $details = null): void {
    $stmt = db()->prepare('INSERT INTO admin_audit_log (admin_user_id,action,entity_type,entity_id,details) VALUES (?,?,?,?,?)');
    $stmt->execute([$adminUserId, $action, $entityType, $entityId, $details]);
}

require_once __DIR__ . '/questions.php';
require_once __DIR__ . '/comments.php';
require_once __DIR__ . '/evidence.php';
require_once __DIR__ . '/blog.php';
