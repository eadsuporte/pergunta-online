<?php

declare(strict_types=1);

require_once __DIR__ . '/blog_service.php';
api_method(['POST']);
$actor = api_require_auth();

api_handle(static fn(): array => api_blog_batch(api_payload_items(), $actor));
