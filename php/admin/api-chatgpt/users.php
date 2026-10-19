<?php

declare(strict_types=1);

require_once __DIR__ . '/common.php';
api_method(['GET', 'POST']);
$actor = api_require_auth();

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    api_handle(static fn(): array => api_users_search((string)($_GET['q'] ?? '')));
}
api_handle(static function () use ($actor): array {
    $input = api_payload();
    if (array_is_list($input)) {
        throw new InvalidArgumentException('Envie um único usuário como objeto.');
    }
    db()->beginTransaction();
    $result = api_user_create($input, $actor);
    db()->commit();
    return $result;
});
