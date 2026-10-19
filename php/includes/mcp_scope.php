<?php

declare(strict_types=1);

/**
 * Build RFC 6750 OAuth scope challenges without granting new permissions.
 * An additional scope always requires explicit administrator consent.
 */
function mcp_scope_union(string $required, string $granted = ''): string {
    $supported = ['questions:read', 'questions:write', 'users:write'];
    if (!in_array($required, $supported, true)) {
        throw new InvalidArgumentException('Unsupported required OAuth scope.');
    }
    $previous = preg_split('/\s+/', trim($granted)) ?: [];
    $result = array_values(array_unique(array_merge(
        array_values(array_intersect($previous, [...$supported, 'offline_access'])),
        [$required]
    )));
    return implode(' ', $result);
}

/** Tell the client which additional scope to request from the OAuth server. */
function mcp_scope_challenge(string $base, string $required, string $granted = ''): string {
    mcp_scope_assert_base($base);
    return 'Bearer realm="Pergunta.Online", error="insufficient_scope", '
        . 'error_description="Additional administrator consent required", '
        . 'scope="' . mcp_scope_union($required, $granted) . '", '
        . 'resource_metadata="' . rtrim($base, '/') . '/.well-known/oauth-protected-resource"';
}

/** Advertise the scopes of the administrative MCP catalog during initial login. */
function mcp_scope_initial_challenge(string $base): string {
    mcp_scope_assert_base($base);
    return 'Bearer realm="Pergunta.Online", '
        . 'scope="questions:read questions:write users:write", '
        . 'resource_metadata="' . rtrim($base, '/') . '/.well-known/oauth-protected-resource"';
}

function mcp_scope_assert_base(string $base): void {
    if (!filter_var($base, FILTER_VALIDATE_URL)
        || !str_starts_with($base, 'https://')
        || strpbrk($base, "\r\n\"") !== false) {
        throw new InvalidArgumentException('Invalid MCP HTTPS base URL.');
    }
}
