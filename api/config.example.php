<?php

declare(strict_types=1);

// Скопировать в /home/httpd/vhosts/trexgo.ru/private/leads-config.php.
// Настоящий файл находится вне DocumentRoot и никогда не коммитится.
return [
    'database' => [
        'dsn' => 'mysql:unix_socket=/home/mysql/mysql.sock;dbname=a250227_trexgo;charset=utf8mb4',
        'username' => 'a250227_trexgo',
        'password' => 'CHANGE_ME',
        'name' => 'a250227_trexgo',
        'host' => 'a250227.mysql.mchost.ru',
        'socket' => '/home/mysql/mysql.sock',
    ],
    'security' => [
        // Сгенерировать: php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"
        'rate_limit_key' => 'CHANGE_ME_TO_64_RANDOM_HEX_CHARACTERS',
        'allowed_hosts' => ['trexgo.ru', 'www.trexgo.ru'],
        'min_fill_seconds' => 2,
        'max_fill_seconds' => 86400,
        'rate_limit_hits' => 5,
        'rate_limit_window_seconds' => 900,
    ],
    'notifications' => [
        'telegram_bot_token' => '',
        'telegram_chat_id' => '',
        'mail_to' => '',
        'mail_from' => 'noreply@trexgo.ru',
    ],
    'consent_text_version' => '2026-08-07',
    'fallback' => [
        'phone' => '+7 985 075-76-75',
        'whatsapp_url' => 'https://wa.me/79850757675',
    ],
    'yandex_disk' => [
        'client_id' => '',
        'client_secret' => '',
        'refresh_token' => '',
        'path' => 'app:/TrexGo — заявки.xlsx',
    ],
    'paths' => [
        'exports' => '/home/httpd/vhosts/trexgo.ru/private/exports',
        'backups' => '/home/httpd/vhosts/trexgo.ru/private/backups',
    ],
    'mysqldump_path' => '/usr/bin/mysqldump',
];
