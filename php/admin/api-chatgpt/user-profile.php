<?php

declare(strict_types=1);

require_once __DIR__ . '/common.php';
api_method(['POST']);
$actor = api_require_auth();

api_handle(static function () use ($actor): array {
    $input = api_payload();
    db()->beginTransaction();
    $result = api_user_profile_update($input, $actor);
    db()->commit();
    return $result;
});
