<?php

declare(strict_types=1);

// Run without a database: php php/tests/question_comments_test.php
require_once dirname(__DIR__) . '/includes/comments.php';

function comment_expect(bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function comment_expect_invalid(callable $callback): void {
    try {
        $callback();
    } catch (InvalidArgumentException $e) {
        return;
    }
    throw new RuntimeException('Expected InvalidArgumentException.');
}

$body = question_comment_validate(['id' => 42, 'name' => 'Usuário'], "  Olá, comunidade!\n  ");
comment_expect($body === 'Olá, comunidade!', 'Authenticated comment should be trimmed.');
comment_expect(question_comment_length('ação 😀') === 6, 'Length must count UTF-8 characters, not bytes.');
comment_expect(question_comment_length(chr(255)) === -1, 'Invalid UTF-8 should be rejected.');

comment_expect_invalid(static fn() => question_comment_validate(null, 'Comentário válido.'));
comment_expect_invalid(static fn() => question_comment_validate([], 'Comentário válido.'));
comment_expect_invalid(static fn() => question_comment_validate(['id' => 0], 'Comentário válido.'));
comment_expect_invalid(static fn() => question_comment_validate(['id' => -1], 'Comentário válido.'));
comment_expect_invalid(static fn() => question_comment_validate(['id' => 42], ' '));
comment_expect_invalid(static fn() => question_comment_validate(['id' => 42], str_repeat('a', 2001)));
comment_expect_invalid(static fn() => question_comment_validate(['id' => 42], chr(255) . 'abc'));

// Must reject guests before attempting a database query, even if the caller bypasses the page.
comment_expect_invalid(static fn() => question_comment_create(17, null, 'Comentário válido.'));
comment_expect_invalid(static fn() => question_comment_create(17, ['id' => 0], 'Comentário válido.'));

echo "Question comment validation tests passed.\n";
