<?php

declare(strict_types=1);

// Optional deployment check. Does not mutate server data.
// PERGUNTA_MCP_URL=https://example.org/mcp PERGUNTA_MCP_TOKEN=... php php/tests/mcp_live_discovery_test.php

$url = trim((string)getenv('PERGUNTA_MCP_URL'));
$token = trim((string)getenv('PERGUNTA_MCP_TOKEN'));
if (!preg_match('~^https://[^?#]+/mcp$~', $url) || $token === '') {
    fwrite(STDERR, "Set PERGUNTA_MCP_URL (HTTPS ending /mcp) and PERGUNTA_MCP_TOKEN.\n");
    exit(2);
}

function discovery_request(string $url, string $token, string $method, int $id): array {
    $payload = json_encode([
        'jsonrpc' => '2.0',
        'id' => $id,
        'method' => $method,
        'params' => $method === 'initialize' ? [
            'protocolVersion' => '2025-06-18',
            'capabilities' => new stdClass(),
            'clientInfo' => ['name' => 'pergunta-online-deployment-test', 'version' => '1'],
        ] : new stdClass(),
    ], JSON_THROW_ON_ERROR);
    $context = stream_context_create(['http' => [
        'method' => 'POST',
        'timeout' => 15,
        'ignore_errors' => true,
        'header' => "Content-Type: application/json\r\nAccept: application/json\r\n" .
            "Authorization: Bearer {$token}\r\nMCP-Protocol-Version: 2025-06-18\r\n",
        'content' => $payload,
    ]]);
    $body = @file_get_contents($url, false, $context);
    if (!is_string($body)) {
        throw new RuntimeException('Could not contact MCP endpoint.');
    }
    $httpStatus = $http_response_header[0] ?? '';
    if (!str_contains($httpStatus, ' 200 ')) {
        throw new RuntimeException('MCP returned ' . $httpStatus . '. Check deployment and OAuth scopes.');
    }
    $response = json_decode($body, true, 64, JSON_THROW_ON_ERROR);
    if (!is_array($response) || isset($response['error']) || !isset($response['result'])) {
        throw new RuntimeException('Invalid MCP response for ' . $method . '.');
    }
    return $response['result'];
}

require_once dirname(__DIR__) . '/admin/api-chatgpt/mcp_tools.php';

try {
    $initialized = discovery_request($url, $token, 'initialize', 1);
    $version = (string)($initialized['serverInfo']['version'] ?? '');
    if ($version !== MCP_SERVER_VERSION) {
        throw new RuntimeException('Deployed MCP version ' . ($version ?: '(missing)') .
            ' differs from repository ' . MCP_SERVER_VERSION . '. Deploy the new PHP files.');
    }
    $listed = discovery_request($url, $token, 'tools/list', 2);
    $actual = array_column($listed['tools'] ?? [], 'name');
    $expected = array_column(mcp_tools(), 'name');
    if (array_diff($expected, $actual) !== [] || count($actual) !== count($expected)) {
        throw new RuntimeException('Deployment tool catalog is stale: missing ' .
            implode(', ', array_diff($expected, $actual)) .
            '. Deploy, then refresh the MCP connector in ChatGPT.');
    }
    echo "Remote MCP discovery passed: {$version}, " . count($actual) . " tools.\n";
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}
