<?php

declare(strict_types=1);

// Run without a database: php php/tests/mcp_import_contracts_test.php.
require_once dirname(__DIR__) . '/admin/api-chatgpt/historical.php';

function mcp_expect(bool $value, string $label): void {
    if (!$value) {
        throw new RuntimeException('Failed: ' . $label);
    }
}

function mcp_expect_invalid(callable $callback, string $label): void {
    try {
        $callback();
    } catch (InvalidArgumentException $e) {
        return;
    }
    throw new RuntimeException('Expected failure: ' . $label);
}

mcp_expect(api_historical_date('2020-01-01 12:34:56', 'created_at') === '2020-01-01 12:34:56',
    'historical timestamp');
mcp_expect(api_historical_date('2020-01-01T12:34:56', 'created_at') === '2020-01-01 12:34:56',
    'ISO-style local timestamp');
mcp_expect_invalid(static fn() => api_historical_date('2020-02-30 01:00:00', 'created_at'),
    'impossible date');
mcp_expect_invalid(static fn() => api_historical_date('2099-01-01 01:00:00', 'created_at'),
    'future imported date');
mcp_expect_invalid(static fn() => api_historical_date('2020-01-01 00:00:00',
    'vote.created_at', '2020-01-02 00:00:00'), 'vote before question');
mcp_expect_invalid(static fn() => api_historical_date('2020-01-01 00:00:00',
    'activity_at', '2020-01-02 00:00:00'), 'activity before user registration');

$perfil = [
    'estilo_escrita' => 'coloquial',
    'time_coracao' => 'Vasco da Gama',
    'usa_emojis' => true,
    'emojis_frequentes' => [':)', '⚽'],
    'abreviacoes' => ['vc', 'tbm'],
];
mcp_expect(api_perfil_decode(api_perfil_encode($perfil)) === $perfil, 'writing profile round trip');

$apiRoot = dirname(__DIR__) . '/admin/api-chatgpt/';
$mcp = (string)file_get_contents($apiRoot . 'mcp_tools.php');
foreach ([
    'create_blog_post', 'import_blog_posts', 'vote_question', 'import_votes',
    'add_comment', 'upsert_category', 'create_question', 'import_questions',
] as $name) {
    mcp_expect(str_contains($mcp, 'mcp_tool("' . $name . '"'), 'MCP tool ' . $name);
}
$service = (string)file_get_contents($apiRoot . 'blog_service.php');
mcp_expect(str_contains($service, 'function api_blog_batch('), 'reusable blog batch');
mcp_expect(str_contains($service, "api_touch_user_activity((int)\$prepared['author_id']"),
    'blog author activity');
$social = (string)file_get_contents($apiRoot . 'social.php');
mcp_expect(str_contains($social, 'ON DUPLICATE KEY UPDATE created_at=created_at'),
    'vote idempotency');
mcp_expect(str_contains($social, 'api_touch_user_activity($userId, $createdAt)'),
    'social activity update');
$schema = (string)file_get_contents(dirname(__DIR__) . '/schema.sql');
mcp_expect(str_contains($schema, 'uq_question_comments_external_id'),
    'comment idempotency column');

echo "MCP historical import contracts passed.\n";
