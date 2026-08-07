<?php

declare(strict_types=1);

return [
    'database' => [
        'dsn' => 'mysql:host=127.0.0.1;dbname=unavailable;charset=utf8mb4',
        'username' => 'test',
        'password' => 'test',
    ],
    'security' => [
        'rate_limit_key' => '0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef',
        'allowed_hosts' => ['127.0.0.1'],
        'min_fill_seconds' => 0,
        'max_fill_seconds' => 2147483647,
        'rate_limit_hits' => 5,
        'rate_limit_window_seconds' => 900,
    ],
    'notifications' => [
        'telegram_bot_token' => '',
        'telegram_chat_id' => '',
        'mail_to' => '',
        'mail_from' => '',
    ],
    'consent_text_version' => 'test',
    'fallback' => [
        'phone' => '+7 985 075-76-75',
        'whatsapp_url' => 'https://wa.me/79850757675',
    ],
];
