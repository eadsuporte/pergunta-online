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

[$name, $body] = question_comment_validate(null, '  Visitante :)  ', "  Olá, comunidade!\n  ");
comment_expect($name === 'Visitante :)', 'Guest name should be trimmed.');
comment_expect($body === 'Olá, comunidade!', 'Comment should be trimmed.');

[$name, $body] = question_comment_validate(['id' => 42, 'name' => 'Usuário'], '', 'Comentário válido.');
comment_expect($name === null && $body === 'Comentário válido.', 'Authenticated user should not require a guest name.');

comment_expect(question_comment_length('ação 😀') === 6, 'Length must count UTF-8 characters, not bytes.');
comment_expect(question_comment_length(chr(255)) === -1, 'Invalid UTF-8 should be rejected.');
comment_expect_invalid(static fn() => question_comment_validate(null, '', 'Texto válido.'));
comment_expect_invalid(static fn() => question_comment_validate(null, 'A', 'Texto válido.'));
comment_expect_invalid(static fn() => question_comment_validate(null, str_repeat('a', 121), 'Texto válido.'));
comment_expect_invalid(static fn() => question_comment_validate(null, 'Visitante', ' '));
comment_expect_invalid(static fn() => question_comment_validate(null, 'Visitante', str_repeat('a', 2001)));
comment_expect_invalid(static fn() => question_comment_validate(null, 'Visitante', chr(255) . 'abc'));

echo "Question comment validation tests passed.\n";
