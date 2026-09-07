<?php

declare(strict_types=1);

require_once __DIR__ . '/_cli.php';
require_once __DIR__ . '/lib/GoogleServiceAccountAuth.php';
require_once __DIR__ . '/lib/GoogleSheetsClient.php';
require_once __DIR__ . '/lib/SheetLeadRows.php';

$config = trexgo_config();
$googleConfig = is_array($config['google_sheets'] ?? null) ? $config['google_sheets'] : [];
$keyPath = (string) ($googleConfig['service_account_key_path'] ?? '');
if ($keyPath === '') {
    trexgo_cli_fail('google_sheets.service_account_key_path is not configured');
}

$editors = array_values(array_filter(array_map('trim', (array) ($googleConfig['editors'] ?? []))));
if ($editors === []) {
    trexgo_cli_fail('google_sheets.editors is empty — nobody to share the spreadsheet with');
}

$spreadsheetId = (string) ($googleConfig['spreadsheet_id'] ?? '');
if ($spreadsheetId === '') {
    trexgo_cli_fail('google_sheets.spreadsheet_id is not configured — create the spreadsheet manually first (service accounts cannot create Drive files) and put its id in the config');
}

$auth = new GoogleServiceAccountAuth($keyPath, [
    'https://www.googleapis.com/auth/spreadsheets',
    'https://www.googleapis.com/auth/drive',
]);
$sheets = new GoogleSheetsClient($auth);

$sheets->writeRange(
    $spreadsheetId,
    SheetLeadRows::SHEET_NAME . '!A1',
    [SheetLeadRows::HEADERS]
);
$sheets->writeRange(
    $spreadsheetId,
    SheetLeadRows::LEGEND_SHEET_NAME . '!A1',
    SheetLeadRows::legendRows()
);

foreach ($editors as $email) {
    $sheets->shareWithEditor($spreadsheetId, $email);
}

fwrite(STDOUT, 'spreadsheetId=' . $spreadsheetId . PHP_EOL);
fwrite(STDOUT, 'url=https://docs.google.com/spreadsheets/d/' . $spreadsheetId . '/edit' . PHP_EOL);
