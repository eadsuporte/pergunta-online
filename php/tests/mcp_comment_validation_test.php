<?php

declare(strict_types=1);

// Offline regression for the REST/MCP add_comment path. No database is accessed.
require_once dirname(__DIR__) . '/includes/comments.php';

function comment_test_assert(bool $condition, string $label): void {
    if (!$condition) {
        throw new RuntimeException('Comment validation regression: ' . $label);
    }
}

// Minimal stubs allow loading the actual social.php service without its
// application bootstrap. A valid comment must pass validation and reach db().
function api_string(mixed $value, string $field, int $min = 0, int $max = 0): string {
    return trim((string)$value);
}

function db(): never {
    throw new RuntimeException('Reached database after comment validation.');
}

$temp = sys_get_temp_dir() . '/pergunta-comment-test-' . bin2hex(random_bytes(8));
if (!mkdir($temp, 0700)) {
    throw new RuntimeException('Could not create temporary test directory.');
}

try {
    $source = dirname(__DIR__) . '/admin/api-chatgpt/social.php';
    comment_test_assert(copy($source, $temp . '/social.php'), 'copy social.php');
    comment_test_assert(file_put_contents($temp . '/common.php', "<?php\n") !== false,
        'stub application bootstrap');
    require $temp . '/social.php';

    foreach (['OK', '  Comentário válido com acentuação.  ', 'á😀', str_repeat('ç', 2000)] as $body) {
        try {
            api_comment_question(['question_id' => 8, 'user_id' => 7, 'body' => $body], ['id' => 99]);
            throw new RuntimeException('Expected the database boundary to be reached.');
        } catch (RuntimeException $e) {
            comment_test_assert($e->getMessage() === 'Reached database after comment validation.',
                'valid comment accepted before persistence');
        }
    }

    foreach (['', ' ', 'X', '  X  ', str_repeat('a', 2001)] as $body) {
        try {
            api_comment_question(['question_id' => 8, 'user_id' => 7, 'body' => $body], ['id' => 99]);
            throw new RuntimeException('Expected invalid comment to be rejected.');
        } catch (InvalidArgumentException $e) {
            comment_test_assert(str_contains($e->getMessage(), '2 e 2.000 caracteres'),
                'invalid comment length rejected');
        }
    }
} finally {
    @unlink($temp . '/social.php');
    @unlink($temp . '/common.php');
    @rmdir($temp);
}

echo "MCP comment validation regression passed.\n";
