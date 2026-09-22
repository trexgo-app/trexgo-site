<?php

declare(strict_types=1);

// Партнёрские заявки: код партнёра в заявке, запрос в кабинет партнёров и блок «Партнёр»
// в уведомлении. Отдельно от run.php, чтобы не зависеть от его внешних библиотек.
// Запуск: php tests/partners.php

require_once dirname(__DIR__) . '/api/lib/bootstrap.php';
require_once dirname(__DIR__) . '/api/lib/validation.php';
require_once dirname(__DIR__) . '/api/lib/notifications.php';
require_once dirname(__DIR__) . '/api/lib/partners.php';

$tests = 0;

function expect(bool $condition, string $message): void
{
    global $tests;
    $tests++;
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$config = [
    'security' => ['min_fill_seconds' => 2, 'max_fill_seconds' => 86400],
    'consent_text_version' => 'test-v1',
];
$now = 2000000000;
$base = [
    'request_id' => '2f6b5f5f-5a3a-4fef-8fd5-aed5fc64a023',
    'form_kind' => 'lead',
    'phone' => '8 (985) 075-76-75',
    'form_started_at' => ($now - 3) * 1000,
    'page_url' => 'https://trexgo.ru/etrn-za-vas.html?utm_source=partner',
];

$partnerLead = trexgo_validate_submission(
    $base + ['partner_ref' => 'Balans', 'partner_click' => 'AbC_123-xyz', 'company' => 'ООО «Ромашка»'],
    $config,
    $now
);
expect($partnerLead['partner_ref'] === 'balans', 'Partner code should be normalized to lowercase');
expect($partnerLead['partner_click'] === 'AbC_123-xyz', 'Partner click token was lost');
$junk = trexgo_validate_submission($base + ['partner_ref' => '../etc', 'partner_click' => '<script>'], $config, $now);
expect($junk['partner_ref'] === null && $junk['partner_click'] === null, 'Malformed partner fields must be dropped, not rejected');
expect(trexgo_partner_lead(trexgo_validate_submission($base, $config, $now), $config) === null, 'Lead without partner must not call the cabinet');

$keyFile = __DIR__ . '/.partner-key-test-' . getmypid();
file_put_contents($keyFile, "test-partner-key-123456789\n");
$partnerConfig = $config + ['partner' => ['key_file' => $keyFile, 'lead_url' => 'https://partner.test/api/public/lead']];
$sent = null;
$fakeCabinet = static function (string $url, array $headers, string $body) use (&$sent): string {
    $sent = ['url' => $url, 'headers' => $headers, 'body' => json_decode($body, true)];
    return json_encode([
        'ok' => true, 'attached' => true,
        'partner' => ['name' => 'Бухгалтерия «Баланс»', 'kind' => 'company', 'code' => 'balans', 'phone' => '+7 916 000-00-01',
            'link' => 'https://partner.trexgo.ru/r/balans'],
        'click' => ['ts' => '2026-09-22T11:03:00.000Z', 'sub' => 'telegram', 'referer_host' => 'web.telegram.org', 'device' => 'Телефон'],
    ], JSON_UNESCAPED_UNICODE);
};
$info = trexgo_partner_lead($partnerLead, $partnerConfig, $fakeCabinet);
expect($sent['headers']['X-Partner-Key'] === 'test-partner-key-123456789', 'Partner key must be read from the key file');
expect($sent['body']['ref'] === 'balans' && $sent['body']['rc'] === 'AbC_123-xyz' && $sent['body']['phone'] === '+79850757675',
    'Cabinet must receive partner code, click and contacts');
expect($info['status'] === 'attached' && $info['partner']['name'] === 'Бухгалтерия «Баланс»', 'Partner info was not parsed');

$html = trexgo_notification_html($partnerLead + ['partner' => $info], false);
expect(str_contains($html, 'Новая заявка TrexGo — от партнёра'), 'Partner lead must be marked in the title');
expect(str_contains($html, '🤝 <b>Партнёр: Бухгалтерия «Баланс» (компания)</b>'), 'Partner name missing in Telegram message');
foreach (['Площадка: telegram', 'Перешёл с: web.telegram.org', 'Телефон, переход 22.09.2026 14:03 МСК', 'https://partner.trexgo.ru/r/balans', 'Телефон партнёра: +7 916 000-00-01'] as $needle) {
    expect(str_contains($html, htmlspecialchars($needle, ENT_QUOTES, 'UTF-8')), "Telegram message lacks: {$needle}");
}
$plain = trexgo_notification_text($partnerLead + ['partner' => $info], false);
expect(str_contains($plain, 'Партнёр: Бухгалтерия «Баланс» (компания)') && str_contains($plain, 'Площадка: telegram'), 'Plain notification lacks partner');

$other = trexgo_partner_lead($partnerLead, $partnerConfig, static fn (): string => json_encode([
    'ok' => true, 'attached' => false, 'duplicate' => true,
    'partner' => ['name' => 'Марина', 'kind' => 'person', 'code' => 'marina', 'link' => 'x'], 'click' => null,
    'owner' => ['name' => 'ГрузЛайн', 'kind' => 'company', 'code' => 'gruzline'],
], JSON_UNESCAPED_UNICODE));
$otherText = implode("\n", trexgo_partner_lines($other));
expect(str_contains($otherText, 'Партнёр: Марина (физлицо)') && str_contains($otherText, 'уже закреплён за партнёром «ГрузЛайн»'),
    'Duplicate owned by another partner must be flagged');

$down = trexgo_partner_lead($partnerLead, $partnerConfig, static fn (): ?string => null);
expect($down['status'] === 'unavailable' && str_contains(implode("\n", trexgo_partner_lines($down)), 'balans'),
    'If the cabinet is down the notification still shows the partner code');
$unknown = trexgo_partner_lead($partnerLead, $partnerConfig, static fn (): string => '{"ok":true,"attached":false}');
expect($unknown['status'] === 'unknown', 'Unknown partner code must be reported');
$noKey = trexgo_partner_lead($partnerLead, $config + ['partner' => ['key_file' => '/nonexistent/key']], $fakeCabinet);
expect($noKey['status'] === 'unavailable', 'Missing key file must not break the lead');
unlink($keyFile);

$plainLead = trexgo_notification_html(trexgo_validate_submission($base, $config, $now) + ['partner' => null], false);
expect(!str_contains($plainLead, 'партнёр'), 'Ordinary lead must not mention partners');

fwrite(STDOUT, "Passed {$tests} tests" . PHP_EOL);
