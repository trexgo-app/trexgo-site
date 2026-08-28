<?php

declare(strict_types=1);

// Заглушка api/leads.php для stage. Настоящий файл (../../../api/leads.php)
// пишет в боевую базу и шлёт уведомления в Telegram/почту независимо от того,
// с какого домена пришёл запрос — bootstrap.php читает конфиг по абсолютному
// серверному пути, а не по окружению. Разводить stage и production через
// отдельную базу не стали (см. rules/, раздел про отклонённые варианты):
// эта заглушка проще и не создаёт второй источник данных для лидов.
//
// Отвечает ровно тем, чего ждёт script.js (LEADS_ENDPOINT, response.ok
// и result.ok === true) — форма на stage показывает обычный успех, но
// ничего никуда не отправляет и не сохраняет.

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    http_response_code(405);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'error' => 'method_not_allowed']);
    exit;
}

http_response_code(200);
header('Content-Type: application/json; charset=utf-8');
echo json_encode(['ok' => true, 'stored' => 'stage-stub']);
