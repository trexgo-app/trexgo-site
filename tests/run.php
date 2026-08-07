<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/api/lib/bootstrap.php';
require_once dirname(__DIR__) . '/api/lib/validation.php';
require_once dirname(__DIR__) . '/api/lib/notifications.php';
require_once dirname(__DIR__) . '/ops/vendor/shuchkin/simplexlsx/src/SimpleXLSX.php';
require_once dirname(__DIR__) . '/ops/vendor/shuchkin/simplexlsxgen/src/SimpleXLSXGen.php';
require_once dirname(__DIR__) . '/ops/lib/XlsxLeadSheet.php';

$tests = 0;

function expect(bool $condition, string $message): void
{
    global $tests;
    $tests++;
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function expect_validation_error(callable $callback, string $field): void
{
    try {
        $callback();
    } catch (TrexgoValidationException $error) {
        expect($error->field === $field, "Expected validation field {$field}, got {$error->field}");
        return;
    }
    throw new RuntimeException("Expected validation error for {$field}");
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
    'page_url' => 'https://trexgo.ru/?utm_source=test',
];

expect(trexgo_normalize_phone('8 (985) 075-76-75') === '+79850757675', 'Russian phone normalization failed');
expect(trexgo_normalize_phone('9850757675') === '+79850757675', 'Ten-digit phone normalization failed');
expect(trexgo_is_uuid(trexgo_uuid_v4()), 'Generated UUID is invalid');

$lead = trexgo_validate_submission($base, $config, $now);
expect($lead['phone'] === '+79850757675', 'Validated phone is wrong');
expect($lead['consent_text_version'] === 'test-v1', 'Consent version is missing');

expect_validation_error(
    static fn (): array => trexgo_validate_submission(array_merge($base, ['request_id' => 'bad']), $config, $now),
    'request_id'
);
expect_validation_error(
    static fn (): array => trexgo_validate_submission(array_merge($base, ['phone' => '123']), $config, $now),
    'phone'
);
expect_validation_error(
    static fn (): array => trexgo_validate_submission(
        array_merge($base, ['form_started_at' => ($now - 1) * 1000]),
        $config,
        $now
    ),
    'form_started_at'
);

$subscription = trexgo_validate_submission(array_merge($base, [
    'form_kind' => 'subscription',
    'phone' => '',
    'email' => 'USER@EXAMPLE.COM',
]), $config, $now);
expect($subscription['email'] === 'user@example.com', 'Subscription email normalization failed');

$notification = trexgo_notification_text($lead, true);
expect(str_starts_with($notification, 'БАЗА НЕДОСТУПНА'), 'Fallback notification marker is missing');
expect(str_contains($notification, '+79850757675'), 'Notification lost the phone number');

$sheet = new XlsxLeadSheet();
$path = sys_get_temp_dir() . '/trexgo-xlsx-test-' . bin2hex(random_bytes(4)) . '.xlsx';
$row = array_fill_keys(XlsxLeadSheet::HEADERS, '');
$row['id'] = '2f6b5f5f-5a3a-4fef-8fd5-aed5fc64a023';
$row['Телефон'] = '+79850757675';
$row['Комментарий клиента'] = '=не формула';
$row['Статус'] = 'new';
$sheet->write($path, [$row]);
$roundTrip = $sheet->read($path);
@unlink($path);
expect(count($roundTrip) === 1, 'XLSX row count changed');
expect($roundTrip[0] === $row, 'XLSX round trip changed cell values');

fwrite(STDOUT, "Passed {$tests} tests" . PHP_EOL);
