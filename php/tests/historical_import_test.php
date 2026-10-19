<?php

declare(strict_types=1);

// Execute with: php php/tests/historical_import_test.php
require_once dirname(__DIR__) . '/admin/api-chatgpt/historical.php';

function expect_invalid(callable $callback): void {
    try {
        $callback();
    } catch (InvalidArgumentException $e) {
        return;
    }
    throw new RuntimeException('Expected InvalidArgumentException.');
}

$past = '2020-06-12 13:45:30';
assert(api_historical_date($past, 'created_at') === $past);
assert(api_historical_date('2020-06-12T13:45:30', 'created_at') === $past);
assert(api_historical_date(null, 'created_at') === null);
expect_invalid(static fn() => api_historical_date('2020-02-30 10:00:00', 'created_at'));
expect_invalid(static fn() => api_historical_date('2099-01-01 10:00:00', 'created_at'));
expect_invalid(static fn() => api_historical_date('2020-06-12 13:45:29', 'updated_at', $past));
expect_invalid(static fn() => api_historical_date('not a date', 'created_at'));
assert(api_historical_date('2020-06-12 13:45:31', 'updated_at', $past) === '2020-06-12 13:45:31');

$perfil = [
    'estilo_escrita' => 'conversacional',
    'time_coracao' => 'Vasco da Gama',
    'usa_emojis' => true,
    'emojis_frequentes' => [':)', '⚽'],
    'abreviacoes' => ['vc', 'tbm'],
    'exemplos_escrita' => ['vc viu o jogo? :)'],
];
$encoded = api_perfil_encode($perfil);
assert(is_string($encoded));
assert(api_perfil_decode($encoded) === $perfil);
assert(api_perfil_decode(api_perfil_encode('texto coloquial')) === ['observacoes' => 'texto coloquial']);
assert(api_perfil_encode(null) === null);
assert(api_perfil_encode([]) === '{}');
expect_invalid(static fn() => api_perfil_encode(['text' => str_repeat('x', 33000)]));
expect_invalid(static fn() => api_perfil_encode([true, false]));

echo "Historical import validation tests passed.\n";
