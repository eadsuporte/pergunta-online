<?php

declare(strict_types=1);

if (realpath((string)($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    http_response_code(404);
    exit;
}

return [
    'database' => [
        'host' => '127.0.0.1',
        'port' => 3306,
        'name' => 'pergunta_online',
        'user' => 'pergunta_online',
        'password' => 'troque-esta-senha',
    ],

    // Leave empty to detect the current domain and subdirectory automatically.
    'app_url' => 'https://pergunta-online.diegoramos.tech',

    // Prefer the CHATGPT_API_TOKEN and CHATGPT_API_ACTOR_EMAIL environment variables in production.
    'chatgpt_api' => [
        'token' => '',
        'actor_email' => '',
    ],
];
