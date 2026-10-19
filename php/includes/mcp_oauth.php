<?php

declare(strict_types=1);

require_once __DIR__ . "/bootstrap.php";
require_once __DIR__ . "/mcp_scope.php";

/**
 * OAuth 2.1 authorization-code + PKCE support for the standalone MCP endpoint.
 * Credentials are opaque, cryptographically random and stored only as SHA-256 hashes.
 */
function mcp_base_url(): string {
    global $appconfig;
    $url = rtrim((string)((($appconfig["app_url"] ?? "") ?: "https://pergunta-online.diegoramos.tech")), "/");
    if (!preg_match("~^https://[^/]+(?:/.*)?$~i", $url)) {
        throw new RuntimeException("Configure app_url com uma URL HTTPS publica antes de habilitar o MCP.");
    }
    return $url;
}

function mcp_respond(array $data, int $status = 200): never {
    http_response_code($status);
    header("Content-Type: application/json; charset=utf-8");
    header("Cache-Control: no-store");
    header("X-Content-Type-Options: nosniff");
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

function mcp_oauth_error(string $error, int $status = 400): never {
    if ($status === 403) {
        error_log("[MCP OAuth] HTTP 403: reason=" . $error);
    }
    mcp_respond(["error" => $error], $status);
}

function mcp_oauth_schema(): void {
    // db() runs the versioned database migrations during initialization.
    db();
}

function mcp_random_token(): string {
    return rtrim(strtr(base64_encode(random_bytes(32)), "+/", "-_"), "=");
}

function mcp_oauth_redirects(mixed $value): array {
    if (!is_array($value) || !array_is_list($value) || count($value) < 1 || count($value) > 5) {
        throw new InvalidArgumentException("redirect_uris deve conter de 1 a 5 URLs.");
    }
    $result = [];
    foreach ($value as $uri) {
        if (!is_string($uri) || strlen($uri) > 1000 || preg_match("~^https://(?:chatgpt\\.com|chat\\.openai\\.com|[a-z0-9.-]+\\.openai\\.com)(?::443)?/[^#]*$~i", $uri) !== 1) {
            throw new InvalidArgumentException("Somente URLs de retorno HTTPS oficiais do ChatGPT/OpenAI sao permitidas.");
        }
        $result[] = $uri;
    }
    return array_values(array_unique($result));
}

function mcp_oauth_scopes(string $value): string {
    $parts = preg_split("/\\s+/", trim($value)) ?: [];
    if ($parts === [""]) {
        return "questions:read";
    }
    $parts = array_values(array_unique($parts));
    if (array_diff($parts, ["questions:read", "questions:write", "users:write", "offline_access"]) !== []) {
        throw new InvalidArgumentException("Escopo OAuth invalido.");
    }
    return implode(" ", $parts);
}

function mcp_oauth_metadata(string $type): never {
    $base = mcp_base_url();
    if ($type === "resource") {
        mcp_respond([
            "resource" => $base . "/mcp",
            "authorization_servers" => [$base],
            "scopes_supported" => ["questions:read", "questions:write", "users:write", "offline_access"],
            "bearer_methods_supported" => ["header"],
        ]);
    }
    mcp_respond([
        "issuer" => $base,
        "authorization_endpoint" => $base . "/oauth/authorize",
        "token_endpoint" => $base . "/oauth/token",
        "registration_endpoint" => $base . "/oauth/register",
        "response_types_supported" => ["code"],
        "grant_types_supported" => ["authorization_code", "refresh_token"],
        "code_challenge_methods_supported" => ["S256"],
        "token_endpoint_auth_methods_supported" => ["none"],
        "scopes_supported" => ["questions:read", "questions:write", "users:write", "offline_access"],
    ]);
}

function mcp_oauth_register(): never {
    mcp_oauth_schema();
    if (strtoupper((string)($_SERVER["REQUEST_METHOD"] ?? "")) !== "POST") {
        mcp_oauth_error("method_not_allowed", 405);
    }
    $raw = file_get_contents("php://input", false, null, 0, 16385);
    if (!is_string($raw) || strlen($raw) > 16384) {
        mcp_oauth_error("invalid_client_metadata");
    }
    $data = json_decode($raw, true);
    if (!is_array($data) || array_is_list($data)) {
        mcp_oauth_error("invalid_client_metadata");
    }
    try {
        $redirects = mcp_oauth_redirects($data["redirect_uris"] ?? null);
    } catch (InvalidArgumentException $e) {
        mcp_oauth_error("invalid_redirect_uri");
    }
    if (($data["token_endpoint_auth_method"] ?? "none") !== "none") {
        mcp_oauth_error("invalid_client_metadata");
    }
    $name = trim((string)($data["client_name"] ?? "ChatGPT"));
    if ($name === "" || strlen($name) > 190) {
        mcp_oauth_error("invalid_client_metadata");
    }
    $id = mcp_random_token();
    db()->prepare("INSERT INTO mcp_oauth_clients (client_id,client_name,redirect_uris) VALUES (?,?,?)")
        ->execute([$id, $name, json_encode($redirects, JSON_UNESCAPED_SLASHES)]);
    mcp_respond([
        "client_id" => $id,
        "client_name" => $name,
        "redirect_uris" => $redirects,
        "token_endpoint_auth_method" => "none",
        "grant_types" => ["authorization_code", "refresh_token"],
        "response_types" => ["code"],
    ], 201);
}

function mcp_oauth_authorize(): never {
    mcp_oauth_schema();
    $method = strtoupper((string)($_SERVER["REQUEST_METHOD"] ?? "GET"));
    if (!in_array($method, ["GET", "POST"], true)) {
        mcp_oauth_error("method_not_allowed", 405);
    }
    if ($method === "GET") {
        $id = (string)($_GET["client_id"] ?? "");
        $stmt = db()->prepare("SELECT client_name,redirect_uris FROM mcp_oauth_clients WHERE client_id=?");
        $stmt->execute([$id]);
        $client = $stmt->fetch();
        $uri = (string)($_GET["redirect_uri"] ?? "");
        $redirects = $client ? json_decode((string)$client["redirect_uris"], true) : [];
        $challenge = (string)($_GET["code_challenge"] ?? "");
        $state = (string)($_GET["state"] ?? "");
        $resource = (string)($_GET["resource"] ?? "");
        if (!$client || !in_array($uri, $redirects, true) ||
            ($_GET["response_type"] ?? "") !== "code" ||
            ($_GET["code_challenge_method"] ?? "") !== "S256" ||
            !preg_match("/^[A-Za-z0-9_-]{43,128}$/", $challenge) ||
            strlen($state) > 1024 ||
            ($resource !== "" && $resource !== mcp_base_url() . "/mcp")) {
            mcp_oauth_error("invalid_request");
        }
        try {
            $scope = mcp_oauth_scopes((string)($_GET["scope"] ?? "questions:read questions:write users:write"));
        } catch (InvalidArgumentException $e) {
            mcp_oauth_error("invalid_scope");
        }
        $user = current_user();
        if (!$user) {
            $return = (string)($_SERVER["REQUEST_URI"] ?? "");
            if (strlen($return) > 4096 || !str_starts_with($return, "/oauth/authorize?")) {
                mcp_oauth_error("login_required", 401);
            }
            $_SESSION["mcp_after_login"] = $return;
            header("Location: " . mcp_base_url() . "/login", true, 302);
            exit;
        }
        if (($user["role"] ?? "") !== "admin") {
            mcp_oauth_error("access_denied", 403);
        }
        $nonce = mcp_random_token();
        $_SESSION["mcp_oauth_pending"] = [
            "nonce" => $nonce, "client_id" => $id, "redirect_uri" => $uri,
            "challenge" => $challenge, "scope" => $scope, "state" => $state,
            "expires" => time() + 600,
        ];
        require_once __DIR__ . "/layout.php";
        render_page("oauth_authorize", [
            "client_name" => (string)$client["client_name"],
            "redirect_uri" => $uri,
            "scope_read" => str_contains($scope, "questions:read"),
            "scope_write" => str_contains($scope, "questions:write"),
            "scope_users" => str_contains($scope, "users:write"),
            "nonce" => $nonce,
            "form_action" => mcp_base_url() . "/oauth/authorize",
        ], "Autorizar integracao ChatGPT");
        exit;
    }

    require_csrf();
    $pending = $_SESSION["mcp_oauth_pending"] ?? null;
    unset($_SESSION["mcp_oauth_pending"]);
    $user = current_user();
    if (!is_array($pending) || !$user || ($user["role"] ?? "") !== "admin" ||
        ($pending["expires"] ?? 0) < time() ||
        !hash_equals((string)$pending["nonce"], (string)($_POST["nonce"] ?? ""))) {
        mcp_oauth_error("invalid_request", 403);
    }
    $redirect = (string)$pending["redirect_uri"];
    $state = (string)$pending["state"];
    if (($_POST["decision"] ?? "") !== "approve") {
        header("Location: " . $redirect . (str_contains($redirect, "?") ? "&" : "?") .
            http_build_query(["error" => "access_denied", "state" => $state], "", "&", PHP_QUERY_RFC3986), true, 302);
        exit;
    }
    $code = mcp_random_token();
    db()->prepare("INSERT INTO mcp_oauth_codes
        (code_hash,client_id,user_id,redirect_uri,code_challenge,scope,expires_at)
        VALUES (?,?,?,?,?,?,DATE_ADD(NOW(), INTERVAL 5 MINUTE))")
        ->execute([hash("sha256", $code), $pending["client_id"], $user["id"], $redirect,
            $pending["challenge"], $pending["scope"]]);
    header("Location: " . $redirect . (str_contains($redirect, "?") ? "&" : "?") .
        http_build_query(["code" => $code, "state" => $state], "", "&", PHP_QUERY_RFC3986), true, 302);
    exit;
}

function mcp_oauth_issue_tokens(string $clientId, int $userId, string $scope): array {
    $access = mcp_random_token();
    $refresh = mcp_random_token();
    $stmt = db()->prepare("INSERT INTO mcp_oauth_tokens
        (token_hash,token_type,client_id,user_id,scope,resource,expires_at)
        VALUES (?,?,?,?,?,?,DATE_ADD(NOW(), INTERVAL ? SECOND))");
    $resource = mcp_base_url() . "/mcp";
    $stmt->execute([hash("sha256", $access), "access", $clientId, $userId, $scope, $resource, 3600]);
    $stmt->execute([hash("sha256", $refresh), "refresh", $clientId, $userId, $scope, $resource, 2592000]);
    return [
        "access_token" => $access, "token_type" => "Bearer", "expires_in" => 3600,
        "refresh_token" => $refresh, "scope" => $scope,
    ];
}

function mcp_oauth_token(): never {
    if (($_SERVER["REQUEST_METHOD"] ?? "") !== "POST") {
        mcp_oauth_error("method_not_allowed", 405);
    }
    mcp_oauth_schema();
    $grant = (string)($_POST["grant_type"] ?? "");
    $clientId = (string)($_POST["client_id"] ?? "");
    $resource = (string)($_POST["resource"] ?? "");
    if ($resource !== "" && $resource !== mcp_base_url() . "/mcp") {
        mcp_oauth_error("invalid_target");
    }
    $check = db()->prepare("SELECT client_id FROM mcp_oauth_clients WHERE client_id=?");
    $check->execute([$clientId]);
    if (!$check->fetchColumn()) {
        mcp_oauth_error("invalid_client", 401);
    }
    $pdo = db();
    try {
        $pdo->beginTransaction();
        if ($grant === "authorization_code") {
            $code = (string)($_POST["code"] ?? "");
            $verifier = (string)($_POST["code_verifier"] ?? "");
            $redirect = (string)($_POST["redirect_uri"] ?? "");
            if (!preg_match("/^[A-Za-z0-9._~-]{43,128}$/", $verifier)) {
                throw new InvalidArgumentException("invalid_grant");
            }
            $stmt = $pdo->prepare("SELECT * FROM mcp_oauth_codes WHERE code_hash=? AND expires_at>NOW() FOR UPDATE");
            $stmt->execute([hash("sha256", $code)]);
            $record = $stmt->fetch();
            if (!$record || $record["client_id"] !== $clientId || $record["redirect_uri"] !== $redirect) {
                throw new InvalidArgumentException("invalid_grant");
            }
            $expected = rtrim(strtr(base64_encode(hash("sha256", $verifier, true)), "+/", "-_"), "=");
            if (!hash_equals((string)$record["code_challenge"], $expected)) {
                throw new InvalidArgumentException("invalid_grant");
            }
            $pdo->prepare("DELETE FROM mcp_oauth_codes WHERE code_hash=?")->execute([hash("sha256", $code)]);
        } else if ($grant === "refresh_token") {
            $token = (string)($_POST["refresh_token"] ?? "");
            $stmt = $pdo->prepare("SELECT * FROM mcp_oauth_tokens WHERE token_hash=? AND token_type='refresh' AND expires_at>NOW() FOR UPDATE");
            $stmt->execute([hash("sha256", $token)]);
            $record = $stmt->fetch();
            if (!$record || $record["client_id"] !== $clientId) {
                throw new InvalidArgumentException("invalid_grant");
            }
            $pdo->prepare("DELETE FROM mcp_oauth_tokens WHERE token_hash=?")->execute([hash("sha256", $token)]);
        } else {
            throw new InvalidArgumentException("unsupported_grant_type");
        }
        $stmt = $pdo->prepare("SELECT id FROM users WHERE id=? AND role='admin' AND active=1");
        $stmt->execute([(int)$record["user_id"]]);
        if (!$stmt->fetchColumn()) {
            throw new InvalidArgumentException("invalid_grant");
        }
        $result = mcp_oauth_issue_tokens($clientId, (int)$record["user_id"], (string)$record["scope"]);
        $pdo->commit();
        mcp_respond($result);
    } catch (InvalidArgumentException $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        mcp_oauth_error($e->getMessage());
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log("MCP OAuth token failure: " . $e->getMessage());
        mcp_oauth_error("server_error", 500);
    }
}

function mcp_oauth_actor(string $requiredScope): array {
    mcp_oauth_schema();
    $auth = (string)($_SERVER["HTTP_AUTHORIZATION"] ?? ($_SERVER["REDIRECT_HTTP_AUTHORIZATION"] ?? ""));
    if ($auth === "" && function_exists("getallheaders")) {
        foreach ((array)getallheaders() as $name => $value) {
            if (strcasecmp((string)$name, "Authorization") === 0) {
                $auth = (string)$value;
                break;
            }
        }
    }
    if (!preg_match("/^Bearer\\s+([A-Za-z0-9_-]{43,128})$/i", trim($auth), $matches)) {
        mcp_oauth_challenge();
    }
    $stmt = db()->prepare("
        SELECT u.id, u.name, u.email, u.role
          FROM users u
         WHERE u.role='admin'
           AND u.active=1
         LIMIT 1");
    $stmt->execute();
    $actor = $stmt->fetch();
    if (!$actor) {
        mcp_oauth_challenge();
    }
    if (!in_array($requiredScope, explode(" ", (string)$actor["scope"]), true)) {
        // Log diagnostic context, never bearer tokens, authorization codes or credentials.
        error_log("[MCP OAuth] HTTP 403: reason=insufficient_scope required=" . $requiredScope
            . " granted=" . (string)$actor["scope"] . " actor_id=" . (int)$actor["id"]);
        header("WWW-Authenticate: "
            . mcp_scope_challenge(mcp_base_url(), $requiredScope, (string)$actor["scope"]));
        mcp_respond([
            "error" => "insufficient_scope",
            "required_scope" => $requiredScope,
            "reauthorization_required" => true,
        ], 403);
    }
    return $actor;
}

function mcp_oauth_challenge(): never {
    header("WWW-Authenticate: " . mcp_scope_initial_challenge(mcp_base_url()));
    mcp_respond(["error" => "unauthorized"], 401);
}
