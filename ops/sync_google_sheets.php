<?php

declare(strict_types=1);

require_once __DIR__ . '/_cli.php';
require_once dirname(__DIR__) . '/api/lib/validation.php';
require_once dirname(__DIR__) . '/api/lib/notifications.php';
require_once __DIR__ . '/lib/GoogleServiceAccountAuth.php';
require_once __DIR__ . '/lib/GoogleSheetsClient.php';
require_once __DIR__ . '/lib/SheetLeadRows.php';
require_once __DIR__ . '/lib/RowValidation.php';

const TREXGO_LEAD_STATUSES = SheetLeadRows::STATUSES;
const TREXGO_SYNC_ALERT_THRESHOLD = 3;

/** @param array<string, string> $row */
function trexgo_manual_row_has_data(array $row): bool
{
    foreach (['Имя', 'Телефон', 'Email', 'Компания', 'Комментарий клиента', 'Источник'] as $column) {
        if (($row[$column] ?? '') !== '') {
            return true;
        }
    }
    return false;
}

function trexgo_sheet_to_utc(?string $value): ?string
{
    $value = trim((string) $value);
    if ($value === '') {
        return null;
    }
    $moscow = new DateTimeZone('Europe/Moscow');
    $utc = new DateTimeZone('UTC');
    foreach (['d.m.Y H:i', 'd.m.Y', 'Y-m-d H:i:s'] as $format) {
        $date = DateTimeImmutable::createFromFormat('!' . $format, $value, $moscow);
        if ($date instanceof DateTimeImmutable) {
            $errors = DateTimeImmutable::getLastErrors();
            if ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0)) {
                return $date->setTimezone($utc)->format('Y-m-d H:i:s');
            }
        }
    }
    throw new TrexgoRowException('Invalid contact date in Google Sheet');
}

function trexgo_utc_to_moscow(?string $value): ?DateTimeImmutable
{
    if ($value === null || $value === '') {
        return null;
    }
    return (new DateTimeImmutable($value, new DateTimeZone('UTC')))
        ->setTimezone(new DateTimeZone('Europe/Moscow'));
}

function trexgo_utc_to_sheet_date(?string $value): string
{
    return trexgo_utc_to_moscow($value)?->format('Y-m-d') ?? '';
}

function trexgo_utc_to_sheet_time(?string $value): string
{
    return trexgo_utc_to_moscow($value)?->format('H:i:s') ?? '';
}

function trexgo_utc_to_sheet_datetime(?string $value): string
{
    return trexgo_utc_to_moscow($value)?->format('Y-m-d H:i:s') ?? '';
}

/** @return array<string, array<string, mixed>> */
function trexgo_fetch_leads(PDO $pdo): array
{
    $statement = $pdo->query('SELECT * FROM leads ORDER BY created_at, id');
    $leads = [];
    while ($lead = $statement->fetch(PDO::FETCH_ASSOC)) {
        $leads[(string) $lead['id']] = $lead;
    }
    return $leads;
}

/** @param array<string, mixed> $lead @return array<string, string> */
function trexgo_lead_to_sheet_row(array $lead): array
{
    return [
        'id' => (string) $lead['id'],
        'Дата создания' => trexgo_utc_to_sheet_date((string) $lead['created_at']),
        'Время создания' => trexgo_utc_to_sheet_time((string) $lead['created_at']),
        'Имя' => (string) ($lead['name'] ?? ''),
        'Телефон' => (string) ($lead['phone'] ?? ''),
        'Email' => (string) ($lead['email'] ?? ''),
        'Компания' => (string) ($lead['company'] ?? ''),
        'Комментарий клиента' => (string) ($lead['comment'] ?? ''),
        'Источник' => (string) ($lead['source'] ?? ''),
        'Страница' => (string) ($lead['page_url'] ?? ''),
        'Статус' => (string) ($lead['status'] ?? 'new'),
        'Заметка' => (string) ($lead['note'] ?? ''),
        'Следующий шаг' => (string) ($lead['next_step'] ?? ''),
        'Дата контакта' => trexgo_utc_to_sheet_datetime($lead['contacted_at'] ?? null),
        'Ответственный' => (string) ($lead['owner'] ?? ''),
        'utm_source' => (string) ($lead['utm_source'] ?? ''),
        'utm_medium' => (string) ($lead['utm_medium'] ?? ''),
        'utm_campaign' => (string) ($lead['utm_campaign'] ?? ''),
        'utm_content' => (string) ($lead['utm_content'] ?? ''),
        'utm_term' => (string) ($lead['utm_term'] ?? ''),
        'yclid' => (string) ($lead['yclid'] ?? ''),
    ];
}

/** @param array<string, string> $row */
function trexgo_insert_manual_lead(PDO $pdo, array $row): string
{
    ['phone' => $phone, 'email' => $email, 'status' => $status]
        = trexgo_validate_manual_row($row, TREXGO_LEAD_STATUSES);

    $id = trexgo_uuid_v4();
    $now = trexgo_utc_now();
    $statement = $pdo->prepare(<<<'SQL'
        INSERT INTO leads (
            id, request_id, created_at, updated_at, name, phone, email, company,
            comment, source, page_url, consent_at, consent_text_version, status,
            note, next_step, contacted_at, owner
        ) VALUES (
            :id, :request_id, :created_at, :updated_at, :name, :phone, :email, :company,
            :comment, :source, :page_url, NULL, NULL, :status,
            :note, :next_step, :contacted_at, :owner
        )
        SQL);
    $statement->execute([
        'id' => $id,
        'request_id' => trexgo_uuid_v4(),
        'created_at' => $now,
        'updated_at' => $now,
        'name' => trexgo_text($row['Имя'] ?? null, 200),
        'phone' => $phone,
        'email' => $email !== '' ? $email : null,
        'company' => trexgo_text($row['Компания'] ?? null, 200),
        'comment' => trexgo_text($row['Комментарий клиента'] ?? null, 5000),
        'source' => trexgo_text($row['Источник'] ?? null, 64) ?? 'manual',
        'page_url' => trexgo_url($row['Страница'] ?? null),
        'status' => $status,
        'note' => trexgo_text($row['Заметка'] ?? null, 10000),
        'next_step' => trexgo_text($row['Следующий шаг'] ?? null, 500),
        'contacted_at' => trexgo_sheet_to_utc($row['Дата контакта'] ?? ''),
        'owner' => trexgo_text($row['Ответственный'] ?? null, 200),
    ]);

    return $id;
}

/** @param array<string, mixed> $lead @param array<string, string> $row */
function trexgo_update_work_fields(PDO $pdo, array $lead, array $row): bool
{
    $status = trexgo_validate_status(trim($row['Статус'] ?? '') ?: (string) $lead['status'], TREXGO_LEAD_STATUSES);
    $values = [
        'status' => $status,
        'note' => trexgo_text($row['Заметка'] ?? null, 10000),
        'next_step' => trexgo_text($row['Следующий шаг'] ?? null, 500),
        'contacted_at' => trexgo_sheet_to_utc($row['Дата контакта'] ?? ''),
        'owner' => trexgo_text($row['Ответственный'] ?? null, 200),
    ];
    foreach ($values as $field => $value) {
        if (($lead[$field] ?? null) !== $value) {
            $statement = $pdo->prepare(<<<'SQL'
                UPDATE leads
                SET status = :status, note = :note, next_step = :next_step,
                    contacted_at = :contacted_at, owner = :owner, updated_at = :updated_at
                WHERE id = :id
                SQL);
            $statement->execute($values + ['updated_at' => trexgo_utc_now(), 'id' => $lead['id']]);
            return true;
        }
    }
    return false;
}

$lockPath = sys_get_temp_dir() . '/trexgo-leads-google-sync.lock';
$lock = fopen($lockPath, 'c');
if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
    trexgo_cli_fail('Another sync process is already running.', 3);
}

$failureCountPath = sys_get_temp_dir() . '/trexgo-leads-google-sync.failures';

try {
    $config = trexgo_config();
    $googleConfig = is_array($config['google_sheets'] ?? null) ? $config['google_sheets'] : [];
    $spreadsheetId = (string) ($googleConfig['spreadsheet_id'] ?? '');
    $keyPath = (string) ($googleConfig['service_account_key_path'] ?? '');
    if ($spreadsheetId === '' || $keyPath === '') {
        throw new RuntimeException('Google Sheets sync is not configured');
    }

    $auth = new GoogleServiceAccountAuth($keyPath, ['https://www.googleapis.com/auth/spreadsheets', 'https://www.googleapis.com/auth/drive.readonly']);
    $sheets = new GoogleSheetsClient($auth);
    $sheet = new SheetLeadRows();

    $initialModified = $sheets->fileModifiedTime($spreadsheetId);
    $sheetRows = $sheets->readRange($spreadsheetId, SheetLeadRows::SHEET_NAME);
    $rows = $sheet->read($sheetRows);

    $pdo = trexgo_db();
    $leads = trexgo_fetch_leads($pdo);
    $orderedIds = [];
    $databaseChanged = false;
    $skippedRowNotes = [];
    $skippedRowIndexes = [];

    foreach ($rows as $index => &$row) {
        $id = trim($row['id'] ?? '');
        if ($id === '') {
            if (!trexgo_manual_row_has_data($row)) {
                continue;
            }
            try {
                $id = trexgo_insert_manual_lead($pdo, $row);
            } catch (TrexgoRowException $error) {
                $skippedRowNotes[] = 'строка ' . ($index + 2) . ': ' . $error->getMessage();
                $skippedRowIndexes[$index] = true;
                continue;
            }
            $row['id'] = $id;
            $databaseChanged = true;
            $leads = trexgo_fetch_leads($pdo);
        }
        if (!trexgo_is_uuid($id) || !isset($leads[$id])) {
            throw new RuntimeException('Google Sheet contains an unknown lead id at row ' . ($index + 2));
        }
        if (isset($orderedIds[$id])) {
            throw new RuntimeException('Google Sheet contains a duplicate lead id at row ' . ($index + 2));
        }
        $orderedIds[$id] = true;
        try {
            $databaseChanged = trexgo_update_work_fields($pdo, $leads[$id], $row) || $databaseChanged;
        } catch (TrexgoRowException $error) {
            $skippedRowNotes[] = 'строка ' . ($index + 2) . ': ' . $error->getMessage();
        }
    }
    unset($row);

    // Собираем вывод в исходном порядке строк листа: пропущенная строка остаётся
    // на своём месте как есть (без id — подхватится, когда её исправят), а не
    // прыгает в конец. Иначе лист переписывался бы каждый прогон только из-за
    // перестановки, даже когда по сути ничего не изменилось.
    $leads = trexgo_fetch_leads($pdo);
    $outputRows = [];
    foreach ($rows as $index => $row) {
        if (isset($skippedRowIndexes[$index])) {
            $outputRows[] = $row;
            continue;
        }
        $id = trim($row['id'] ?? '');
        if ($id !== '' && isset($orderedIds[$id])) {
            $outputRows[] = trexgo_lead_to_sheet_row($leads[$id]);
        }
    }
    foreach ($leads as $id => $lead) {
        if (!isset($orderedIds[$id])) {
            $outputRows[] = trexgo_lead_to_sheet_row($lead);
        }
    }

    if ($skippedRowNotes !== []) {
        trexgo_notify_operational(
            'Синхронизация заявок TrexGo: пропущены строки с ошибками в данных (исправьте и дождитесь следующего прогона):' . PHP_EOL
                . implode(PHP_EOL, $skippedRowNotes),
            $config
        );
        trexgo_log_event('google_sync_rows_skipped', ['rows' => implode('; ', $skippedRowNotes)]);
    }

    $fileChanged = $outputRows !== $rows;
    if ($fileChanged) {
        $currentModified = $sheets->fileModifiedTime($spreadsheetId);
        if ($initialModified !== $currentModified) {
            trexgo_notify_operational(
                'Конфликт синхронизации TrexGo: таблица менялась во время прогона, загрузка пропущена.',
                $config
            );
            trexgo_cli_fail('Sync conflict: Google Sheet changed during the run.', 4);
        }
        $sheets->writeRange($spreadsheetId, SheetLeadRows::SHEET_NAME . '!A1', $sheet->toSheetRows($outputRows));
    }

    if ($fileChanged || $databaseChanged) {
        $ids = array_keys($leads);
        $markSynced = $pdo->prepare('UPDATE leads SET synced_at = :synced_at WHERE id = :id');
        $syncedAt = trexgo_utc_now();
        foreach ($ids as $id) {
            $markSynced->execute(['synced_at' => $syncedAt, 'id' => $id]);
        }
    }

    @unlink($failureCountPath);

    fwrite(
        STDOUT,
        ($fileChanged || $databaseChanged)
            ? 'Synchronization complete: ' . count($leads) . ' leads' . PHP_EOL
            : 'No synchronization changes' . PHP_EOL
    );
} catch (Throwable $error) {
    trexgo_log_event('google_sync_failed', [
        'type' => get_class($error),
        'code' => (string) $error->getCode(),
        'message' => $error->getMessage(),
    ]);

    // Транспортные сбои Google API гасятся ретраями внутри GoogleSheetsClient; то, что
    // долетело сюда, уже пережило их. Всё равно не будим человека на первый же случай —
    // короткие сетевые окна недоступности иногда переживают и несколько попыток подряд.
    // Алерт уходит один раз при пересечении порога, а не на каждый сбой после него —
    // иначе при затяжной недоступности cron засыпает Telegram сообщением каждые 15 минут.
    $consecutiveFailures = ((int) @file_get_contents($failureCountPath)) + 1;
    file_put_contents($failureCountPath, (string) $consecutiveFailures);

    if ($consecutiveFailures === TREXGO_SYNC_ALERT_THRESHOLD && isset($config) && is_array($config)) {
        trexgo_notify_operational(
            'Синхронизация заявок TrexGo не выполнена после трёх запусков. '
                . 'Причина записана в logs/sync_google_sheets.log.',
            $config
        );
    }
    trexgo_cli_fail('Google Sheets synchronization failed. See logs/sync_google_sheets.log.');
} finally {
    if (is_resource($lock)) {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}
