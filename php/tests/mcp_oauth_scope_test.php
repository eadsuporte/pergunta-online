<?php

declare(strict_types=1);

// Offline: php php/tests/mcp_oauth_scope_test.php
require_once dirname(__DIR__) . '/includes/mcp_scope.php';

function scope_expect(bool $ok, string $reason): void {
    if (!$ok) {
        throw new RuntimeException('OAuth scope contract failed: ' . $reason);
    }
}
function scope_expect_invalid(callable $action, string $reason): void {
    try {
        $action();
    } catch (InvalidArgumentException $e) {
        return;
    }
    throw new RuntimeException('Expected invalid input: ' . $reason);
}

$base = 'https://example.test';
$initial = mcp_scope_initial_challenge($base);
scope_expect(str_contains($initial, 'scope="questions:read questions:write users:write"'),
    'initial discovery');
scope_expect(str_contains($initial, 'resource_metadata="https://example.test/.well-known/oauth-protected-resource"'),
    'resource metadata');

$challenge = mcp_scope_challenge($base, 'users:write', 'questions:read questions:write');
scope_expect(str_contains($challenge, 'error="insufficient_scope"'), 'scope signal');
scope_expect(str_contains($challenge, 'scope="questions:read questions:write users:write"'),
    'upgrade scope preserves previously granted scopes');
scope_expect(mcp_scope_union('users:write', 'questions:read questions:read') ===
    'questions:read users:write', 'remove duplicate scope');
scope_expect(mcp_scope_union('users:write', 'questions:read unexpected') ===
    'questions:read users:write', 'ignore unsupported scopes');
scope_expect(mcp_scope_union('users:write', 'questions:read offline_access') ===
    'questions:read offline_access users:write', 'preserve offline access');
scope_expect_invalid(static fn() => mcp_scope_union('admin:write'), 'unsupported scope');
scope_expect_invalid(static fn() => mcp_scope_initial_challenge('http://example.test'), 'non-HTTPS metadata');
scope_expect_invalid(static fn() => mcp_scope_challenge("https://example.test\r\nInjected:1",
    'users:write'), 'header injection');

$oauthSource = (string)file_get_contents(dirname(__DIR__) . '/includes/mcp_oauth.php');
scope_expect(str_contains($oauthSource, 'error_log("[MCP OAuth] HTTP 403: reason="'),
    'log consent HTTP 403');
scope_expect(str_contains($oauthSource, 'error_log("[MCP OAuth] HTTP 403: reason=insufficient_scope'),
    'log missing scope HTTP 403');
scope_expect(str_contains($oauthSource, 'mcp_scope_challenge(mcp_base_url(), $requiredScope,'),
    'send missing scope challenge');
scope_expect(!str_contains($oauthSource, 'mcp_respond(["error" => "insufficient_scope"], 403)'),
    'avoid bare HTTP 403');
echo "MCP OAuth 403 scope and logging contracts passed.\n";
