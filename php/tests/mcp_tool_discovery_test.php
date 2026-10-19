<?php

declare(strict_types=1);

// Offline check: php php/tests/mcp_tool_discovery_test.php
require_once dirname(__DIR__) . '/admin/api-chatgpt/mcp_tools.php';

function discovery_assert(bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException('MCP discovery contract failed: ' . $message);
    }
}

$expected = [
    'search_users', 'create_user', 'update_user_profile', 'answer_question',
    'create_blog_post', 'import_blog_posts', 'vote_question', 'import_votes',
    'add_comment', 'catalog', 'search', 'fetch', 'create_question',
    'import_questions', 'upsert_focus', 'upsert_category', 'upsert_target',
    'add_evidence',
];

$tools = mcp_tools();
$names = array_column($tools, 'name');
discovery_assert(count($tools) === count($expected), 'expected 18 registered tools');
discovery_assert(count(array_unique($names)) === count($names), 'tool names must be unique');
discovery_assert(array_diff($expected, $names) === [], 'missing tools: ' .
    implode(', ', array_diff($expected, $names)));
discovery_assert(array_diff($names, $expected) === [], 'unexpected registered tools');
discovery_assert(version_compare(MCP_SERVER_VERSION, '1.2.0', '>='), 'version must describe the new catalog');

$byName = array_column($tools, null, 'name');
foreach ($tools as $tool) {
    discovery_assert(($tool['inputSchema']['type'] ?? null) === 'object',
        $tool['name'] . ' must have an object input schema');
    discovery_assert(isset($tool['annotations']['readOnlyHint']),
        $tool['name'] . ' must declare readOnlyHint');
}
$userProperties = $byName['create_user']['inputSchema']['properties'];
foreach (['name', 'email', 'created_at', 'updated_at', 'perfil'] as $field) {
    discovery_assert(isset($userProperties[$field]), 'create_user.' . $field);
}
discovery_assert($byName['create_user']['annotations']['readOnlyHint'] === false,
    'create_user must be a write operation');

foreach (['create_question', 'import_questions'] as $name) {
    $schema = $byName[$name]['inputSchema'];
    $properties = $name === 'create_question'
        ? $schema['properties']
        : $schema['properties']['items']['items']['properties'];
    foreach (['user_id', 'created_at', 'updated_at', 'evidence'] as $field) {
        discovery_assert(isset($properties[$field]), $name . '.' . $field);
    }
}
foreach (['question_id', 'user_id', 'body', 'created_at', 'external_id'] as $field) {
    discovery_assert(isset($byName['add_comment']['inputSchema']['properties'][$field]),
        'add_comment.' . $field);
}
foreach (['author_user_id', 'created_at', 'published_at', 'source_url', 'sources'] as $field) {
    discovery_assert(isset($byName['create_blog_post']['inputSchema']['properties'][$field]),
        'create_blog_post.' . $field);
}
foreach (['created_at', 'updated_at'] as $field) {
    discovery_assert(isset($byName['upsert_category']['inputSchema']['properties'][$field]),
        'upsert_category.' . $field);
}

echo 'MCP tool discovery contracts passed (' . count($tools) . ' tools, version ' .
    MCP_SERVER_VERSION . ").\n";
