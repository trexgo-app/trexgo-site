<?php

declare(strict_types=1);

/** @param array<string, mixed> $lead */
function trexgo_notification_title(array $lead, bool $databaseUnavailable): string
{
    $title = $databaseUnavailable ? 'БАЗА НЕДОСТУПНА — новая заявка TrexGo' : 'Новая заявка TrexGo';
    if ($lead['form_kind'] === 'subscription') {
        $title = $databaseUnavailable ? 'БАЗА НЕДОСТУПНА — новая подписка TrexGo' : 'Новая подписка TrexGo';
    }
    return $title;
}

/** @param array<string, mixed> $lead */
function trexgo_notification_text(array $lead, bool $databaseUnavailable): string
{
    $labels = [
        'name' => 'Имя',
        'phone' => 'Телефон',
        'email' => 'Email',
        'company' => 'Компания',
        'comment' => 'Комментарий',
        'source' => 'Источник',
        'page_url' => 'Страница',
        'referrer' => 'Referrer',
        'utm_source' => 'utm_source',
        'utm_medium' => 'utm_medium',
        'utm_campaign' => 'utm_campaign',
        'utm_content' => 'utm_content',
        'utm_term' => 'utm_term',
        'yclid' => 'yclid',
        'request_id' => 'request_id',
    ];

    $lines = [trexgo_notification_title($lead, $databaseUnavailable)];
    foreach ($labels as $field => $label) {
        $value = $lead[$field] ?? null;
        if ($value !== null && $value !== '') {
            $lines[] = $label . ': ' . $value;
        }
    }

    return mb_substr(implode("\n", $lines), 0, 3900, 'UTF-8');
}

/** @param array<string, mixed> $lead */
function trexgo_notification_html(array $lead, bool $databaseUnavailable): string
{
    $esc = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');

    $lines = [($databaseUnavailable ? '⚠️ ' : '') . '<b>' . $esc(trexgo_notification_title($lead, $databaseUnavailable)) . '</b>'];

    $primaryLabels = [
        'name' => 'Имя',
        'phone' => 'Телефон',
        'email' => 'Email',
        'company' => 'Компания',
        'comment' => 'Комментарий',
        'source' => 'Источник',
    ];
    foreach ($primaryLabels as $field => $label) {
        $value = $lead[$field] ?? null;
        if ($value === null || $value === '') {
            continue;
        }
        $value = (string) $value;
        $lines[] = in_array($field, ['phone', 'email'], true)
            ? $label . ': <code>' . $esc($value) . '</code>'
            : $label . ': ' . $esc($value);
    }

    $pageUrl = (string) ($lead['page_url'] ?? '');
    if ($pageUrl !== '') {
        $path = parse_url($pageUrl, PHP_URL_PATH);
        $linkText = is_string($path) && $path !== '' ? $path : $pageUrl;
        $lines[] = 'Страница: <a href="' . $esc($pageUrl) . '">' . $esc($linkText) . '</a>';
    }

    $techLabels = [
        'referrer' => 'Referrer',
        'utm_source' => 'utm_source',
        'utm_medium' => 'utm_medium',
        'utm_campaign' => 'utm_campaign',
        'utm_content' => 'utm_content',
        'utm_term' => 'utm_term',
        'yclid' => 'yclid',
        'request_id' => 'request_id',
    ];
    $techLines = [];
    foreach ($techLabels as $field => $label) {
        $value = $lead[$field] ?? null;
        if ($value === null || $value === '') {
            continue;
        }
        $value = (string) $value;
        $techLines[] = $label . ': ' . (in_array($field, ['yclid', 'request_id'], true)
            ? '<code>' . $esc($value) . '</code>'
            : $esc($value));
    }
    if ($techLines !== []) {
        $lines[] = '<blockquote expandable>' . implode("\n", $techLines) . '</blockquote>';
    }

    return mb_substr(implode("\n", $lines), 0, 3900, 'UTF-8');
}

/** @param array<string, mixed> $lead @param array<string, mixed> $config
 *  @return array{telegram:bool, mail:bool}
 */
function trexgo_notify(array $lead, array $config, bool $databaseUnavailable = false): array
{
    $notificationConfig = is_array($config['notifications'] ?? null) ? $config['notifications'] : [];
    $html = trexgo_notification_html($lead, $databaseUnavailable);
    $plain = trexgo_notification_text($lead, $databaseUnavailable);

    $telegramSent = trexgo_notify_telegram($html, $notificationConfig, 'HTML');
    if (!$telegramSent) {
        $telegramSent = trexgo_notify_telegram($plain, $notificationConfig);
    }

    return [
        'telegram' => $telegramSent,
        'mail' => trexgo_notify_mail($plain, $notificationConfig),
    ];
}

/** @param array<string, mixed> $config @return array{telegram:bool, mail:bool} */
function trexgo_notify_operational(string $text, array $config): array
{
    $notificationConfig = is_array($config['notifications'] ?? null) ? $config['notifications'] : [];
    return [
        'telegram' => trexgo_notify_telegram($text, $notificationConfig),
        'mail' => trexgo_notify_mail($text, $notificationConfig),
    ];
}

/** @param array<string, mixed> $config */
function trexgo_notify_telegram(string $text, array $config, ?string $parseMode = null): bool
{
    $token = (string) ($config['telegram_bot_token'] ?? '');
    $chatId = (string) ($config['telegram_chat_id'] ?? '');
    if (
        preg_match('/^[0-9]+:[A-Za-z0-9_-]+$/', $token) !== 1
        || $chatId === ''
        || !function_exists('curl_init')
    ) {
        return false;
    }

    $params = ['chat_id' => $chatId, 'text' => $text];
    if ($parseMode !== null) {
        $params['parse_mode'] = $parseMode;
    }

    $curl = curl_init('https://api.telegram.org/bot' . $token . '/sendMessage');
    if ($curl === false) {
        return false;
    }
    curl_setopt_array($curl, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query($params),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 3,
        CURLOPT_TIMEOUT => 7,
        CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
    ]);
    $response = curl_exec($curl);
    $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    curl_close($curl);

    if (!is_string($response) || $status < 200 || $status >= 300) {
        return false;
    }
    $decoded = json_decode($response, true);
    return is_array($decoded) && ($decoded['ok'] ?? false) === true;
}

/** @param array<string, mixed> $config */
function trexgo_notify_mail(string $text, array $config): bool
{
    $to = trim((string) ($config['mail_to'] ?? ''));
    $from = trim((string) ($config['mail_from'] ?? ''));
    if ($to === '' || $from === '' || str_contains($to, "\n") || str_contains($from, "\n")) {
        return false;
    }

    $plainSubject = str_starts_with($text, 'БАЗА НЕДОСТУПНА')
        ? 'БАЗА НЕДОСТУПНА — заявка TrexGo'
        : (str_starts_with($text, 'Синхронизация') || str_starts_with($text, 'Конфликт')
            ? 'Синхронизация заявок TrexGo'
            : 'Новая заявка TrexGo');
    $subject = '=?UTF-8?B?' . base64_encode($plainSubject) . '?=';
    $headers = [
        'From: ' . $from,
        'Content-Type: text/plain; charset=UTF-8',
        'Content-Transfer-Encoding: 8bit',
    ];

    return mail($to, $subject, $text, implode("\r\n", $headers));
}
