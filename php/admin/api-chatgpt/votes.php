<?php

declare(strict_types=1);

require_once __DIR__ . '/social.php';
api_method(['POST']);
$actor = api_require_auth();

api_handle(static function () use ($actor): array {
    $items = api_payload_items();
    db()->beginTransaction();
    $results = [];
    foreach ($items as $item) {
        $results[] = api_vote_question($item, $actor);
    }
    db()->commit();
    return ['received' => count($items), 'votes' => $results];
});
