<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . "/includes/mcp_oauth.php";

header("Cache-Control: no-store");
$action = (string)($_GET["action"] ?? "");
try {
    if ($action === "metadata") {
        mcp_oauth_metadata("server");
    }
    if ($action === "resource") {
        mcp_oauth_metadata("resource");
    }
    if ($action === "register") {
        mcp_oauth_register();
    }
    if ($action === "authorize") {
        mcp_oauth_authorize();
    }
    if ($action === "token") {
        mcp_oauth_token();
    }
    mcp_oauth_error("not_found", 404);
} catch (Throwable $e) {
    error_log("MCP OAuth: " . $e->getMessage());
    mcp_oauth_error("server_error", 500);
}
