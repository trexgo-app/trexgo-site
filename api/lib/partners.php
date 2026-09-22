<?php

declare(strict_types=1);

// Партнёрские заявки. Посетитель пришёл по ссылке партнёра (partner.trexgo.ru/r/<код>),
// script.js запомнил код и прислал его с заявкой. Здесь заявка пересылается в кабинет
// партнёров: там появляется клиент партнёра, а в ответ приходит, кто этот партнёр и
// откуда был переход, — это попадает в уведомление о заявке.
//
// Кабинет партнёров живёт на том же хостинге. Ключ для запроса он создаёт сам в
// private/partner-lead-key.txt, отсюда тот же файл только читается — ключ не проходит
// ни через репозитории, ни через людей. Путь и адрес можно переопределить секцией
// 'partner' в private/leads-config.php.

const TREXGO_PARTNER_LEAD_URL = 'https://partner.trexgo.ru/api/public/lead';
const TREXGO_PARTNER_KEY_FILE = '/home/httpd/vhosts/trexgo.ru/private/partner-lead-key.txt';

/**
 * @param array<string, mixed> $lead @param array<string, mixed> $config
 * @param (callable(string, array<string, string>, string): ?string)|null $post для тестов
 * @return array<string, mixed>|null  null — заявка не партнёрская
 */
function trexgo_partner_lead(array $lead, array $config, ?callable $post = null): ?array
{
    $ref = $lead['partner_ref'] ?? null;
    if (!is_string($ref) || $ref === '') {
        return null;
    }
    $settings = is_array($config['partner'] ?? null) ? $config['partner'] : [];
    $url = (string) ($settings['lead_url'] ?? TREXGO_PARTNER_LEAD_URL);
    $keyFile = (string) ($settings['key_file'] ?? TREXGO_PARTNER_KEY_FILE);

    $key = is_file($keyFile) ? trim((string) @file_get_contents($keyFile)) : '';
    if ($key === '') {
        trexgo_log_event('partner_key_missing');
        return ['ref' => $ref, 'status' => 'unavailable'];
    }

    $body = json_encode([
        'ref' => $ref,
        'rc' => $lead['partner_click'] ?? '',
        'company' => $lead['company'] ?? '',
        'name' => $lead['name'] ?? '',
        'phone' => $lead['phone'] ?? '',
        'email' => $lead['email'] ?? '',
        'comment' => $lead['comment'] ?? '',
        'page' => $lead['page_url'] ?? '',
    ], JSON_UNESCAPED_UNICODE);
    $headers = ['Content-Type' => 'application/json', 'X-Partner-Key' => $key];

    $post ??= 'trexgo_partner_http_post';
    try {
        $raw = $post($url, $headers, (string) $body);
    } catch (Throwable $error) {
        $raw = null;
    }
    $response = is_string($raw) ? json_decode($raw, true) : null;
    if (!is_array($response) || ($response['ok'] ?? false) !== true) {
        trexgo_log_event('partner_lead_failed');
        return ['ref' => $ref, 'status' => 'unavailable'];
    }
    if (!is_array($response['partner'] ?? null)) {
        return ['ref' => $ref, 'status' => 'unknown'];
    }

    $status = 'attached';
    if (!empty($response['duplicate'])) {
        $status = empty($response['attached']) ? 'owned_by_other' : 'repeat';
    }
    return [
        'ref' => $ref,
        'status' => $status,
        'partner' => $response['partner'],
        'click' => is_array($response['click'] ?? null) ? $response['click'] : null,
        'owner' => is_array($response['owner'] ?? null) ? $response['owner'] : null,
    ];
}

/** @param array<string, string> $headers */
function trexgo_partner_http_post(string $url, array $headers, string $body): ?string
{
    if (!function_exists('curl_init')) {
        return null;
    }
    $curl = curl_init($url);
    if ($curl === false) {
        return null;
    }
    $lines = [];
    foreach ($headers as $name => $value) {
        $lines[] = $name . ': ' . $value;
    }
    curl_setopt_array($curl, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $body,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 3,
        CURLOPT_TIMEOUT => 5,
        CURLOPT_HTTPHEADER => $lines,
    ]);
    $response = curl_exec($curl);
    $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    curl_close($curl);
    return is_string($response) && $status >= 200 && $status < 300 ? $response : null;
}

/**
 * Строки о партнёре для уведомления, без разметки. Первая — кто партнёр.
 * @param array<string, mixed> $info
 * @return list<string>
 */
function trexgo_partner_lines(array $info): array
{
    $ref = (string) ($info['ref'] ?? '');
    $partner = is_array($info['partner'] ?? null) ? $info['partner'] : null;
    if ($partner === null) {
        return ($info['status'] ?? '') === 'unknown'
            ? ['Партнёрский код: ' . $ref . ' — такого партнёра в кабинете нет']
            : ['Партнёрский код: ' . $ref . ' — кабинет партнёров не ответил, партнёра смотрите по коду'];
    }

    $kind = ($partner['kind'] ?? '') === 'person' ? 'физлицо' : 'компания';
    $lines = ['Партнёр: ' . (string) ($partner['name'] ?? $ref) . ' (' . $kind . ')'];
    if (!empty($partner['phone'])) {
        $lines[] = 'Телефон партнёра: ' . (string) $partner['phone'];
    }
    $lines[] = 'Ссылка партнёра: ' . (string) ($partner['link'] ?? $ref);

    $click = is_array($info['click'] ?? null) ? $info['click'] : null;
    if ($click !== null) {
        $sub = (string) ($click['sub'] ?? '');
        $host = (string) ($click['referer_host'] ?? '');
        $lines[] = 'Площадка: ' . ($sub !== '' ? $sub : 'без метки');
        $lines[] = 'Перешёл с: ' . ($host !== '' ? $host : 'не передано (приложение Telegram/WhatsApp или прямой заход)');
        $when = trexgo_partner_msk_time((string) ($click['ts'] ?? ''));
        $device = (string) ($click['device'] ?? '');
        $meta = array_filter([$device, $when !== '' ? 'переход ' . $when : '']);
        if ($meta !== []) {
            $lines[] = 'Устройство: ' . implode(', ', $meta);
        }
    } else {
        $lines[] = 'Переход: не найден (ссылка без отметки перехода или заявка позже 90 дней)';
    }

    $status = (string) ($info['status'] ?? '');
    if ($status === 'owned_by_other') {
        $owner = is_array($info['owner'] ?? null) ? (string) ($info['owner']['name'] ?? '') : '';
        $lines[] = 'Внимание: клиент с этим телефоном уже закреплён за партнёром «' . $owner . '» — новый не создан';
    } elseif ($status === 'repeat') {
        $lines[] = 'Повторная заявка: клиент уже есть у этого партнёра';
    } else {
        $lines[] = 'В кабинете партнёра: клиент добавлен со статусом «В обработке»';
    }
    return $lines;
}

function trexgo_partner_msk_time(string $iso): string
{
    $ts = strtotime($iso);
    return $ts === false ? '' : gmdate('d.m.Y H:i', $ts + 3 * 3600) . ' МСК';
}
