<?php

declare(strict_types=1);

require_once __DIR__ . '/_cli.php';
require_once dirname(__DIR__) . '/api/lib/validation.php';
require_once dirname(__DIR__) . '/api/lib/notifications.php';
require_once __DIR__ . '/vendor/shuchkin/simplexlsx/src/SimpleXLSX.php';
require_once __DIR__ . '/vendor/shuchkin/simplexlsxgen/src/SimpleXLSXGen.php';
require_once __DIR__ . '/lib/YandexDiskClient.php';
require_once __DIR__ . '/lib/XlsxLeadSheet.php';

const TREXGO_LEAD_STATUSES = XlsxLeadSheet::STATUSES;

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
    throw new RuntimeException('Invalid contact date in XLSX');
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
    $phone = trexgo_normalize_phone($row['Телефон'] ?? '');
    $digits = preg_replace('/\D+/', '', $phone) ?? '';
    if (strlen($digits) < 10 || strlen($digits) > 15) {
        throw new RuntimeException('Manual XLSX row has invalid phone');
    }
    $email = strtolower(trim($row['Email'] ?? ''));
    if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
        throw new RuntimeException('Manual XLSX row has invalid email');
    }
    $status = trim($row['Статус'] ?? '') ?: 'new';
    if (!in_array($status, TREXGO_LEAD_STATUSES, true)) {
        throw new RuntimeException('Manual XLSX row has invalid status');
    }

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
    $status = trim($row['Статус'] ?? '') ?: (string) $lead['status'];
    if (!in_array($status, TREXGO_LEAD_STATUSES, true)) {
        throw new RuntimeException('XLSX row has invalid status');
    }
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

$lockPath = sys_get_temp_dir() . '/trexgo-leads-yandex-sync.lock';
$lock = fopen($lockPath, 'c');
if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
    trexgo_cli_fail('Another sync process is already running.', 3);
}

$downloadPath = sys_get_temp_dir() . '/trexgo-leads-' . bin2hex(random_bytes(6)) . '.xlsx';
$uploadPath = $downloadPath . '.new.xlsx';

try {
    $config = trexgo_config();
    $diskConfig = is_array($config['yandex_disk'] ?? null) ? $config['yandex_disk'] : [];
    $diskPath = (string) ($diskConfig['path'] ?? '');
    if ($diskPath === '') {
        throw new RuntimeException('Yandex Disk path is not configured');
    }

    $disk = new YandexDiskClient($diskConfig);
    $sheet = new XlsxLeadSheet();
    $initialMetadata = $disk->metadata($diskPath);
    $rows = [];
    if ($initialMetadata !== null) {
        $disk->download($diskPath, $downloadPath);
        $rows = $sheet->read($downloadPath);
    }

    $pdo = trexgo_db();
    $leads = trexgo_fetch_leads($pdo);
    $orderedIds = [];
    $databaseChanged = false;

    foreach ($rows as $index => &$row) {
        $id = trim($row['id'] ?? '');
        if ($id === '') {
            if (!trexgo_manual_row_has_data($row)) {
                continue;
            }
            try {
                $id = trexgo_insert_manual_lead($pdo, $row);
            } catch (Throwable $error) {
                throw new RuntimeException('Cannot import manual XLSX row ' . ($index + 2), 0, $error);
            }
            $row['id'] = $id;
            $databaseChanged = true;
            $leads = trexgo_fetch_leads($pdo);
        }
        if (!trexgo_is_uuid($id) || !isset($leads[$id])) {
            throw new RuntimeException('XLSX contains an unknown lead id at row ' . ($index + 2));
        }
        if (isset($orderedIds[$id])) {
            throw new RuntimeException('XLSX contains a duplicate lead id at row ' . ($index + 2));
        }
        $orderedIds[$id] = true;
        $databaseChanged = trexgo_update_work_fields($pdo, $leads[$id], $row) || $databaseChanged;
    }
    unset($row);

    $leads = trexgo_fetch_leads($pdo);
    $outputRows = [];
    foreach (array_keys($orderedIds) as $id) {
        $outputRows[] = trexgo_lead_to_sheet_row($leads[$id]);
    }
    foreach ($leads as $id => $lead) {
        if (!isset($orderedIds[$id])) {
            $outputRows[] = trexgo_lead_to_sheet_row($lead);
        }
    }

    $fileChanged = $initialMetadata === null || $outputRows !== $rows;
    if ($fileChanged) {
        $sheet->write($uploadPath, $outputRows);
        $currentMetadata = $disk->metadata($diskPath);
        $initialModified = $initialMetadata['modified'] ?? null;
        $currentModified = $currentMetadata['modified'] ?? null;
        if ($initialModified !== $currentModified) {
            trexgo_notify_operational(
                'Конфликт синхронизации TrexGo: файл менялся во время прогона, загрузка пропущена.',
                $config
            );
            trexgo_cli_fail('Sync conflict: remote workbook changed during the run.', 4);
        }
        $disk->upload($diskPath, $uploadPath);
    }

    if ($fileChanged || $databaseChanged) {
        $ids = array_keys($leads);
        $markSynced = $pdo->prepare('UPDATE leads SET synced_at = :synced_at WHERE id = :id');
        $syncedAt = trexgo_utc_now();
        foreach ($ids as $id) {
            $markSynced->execute(['synced_at' => $syncedAt, 'id' => $id]);
        }
    }

    fwrite(
        STDOUT,
        ($fileChanged || $databaseChanged)
            ? 'Synchronization complete: ' . count($leads) . ' leads' . PHP_EOL
            : 'No synchronization changes' . PHP_EOL
    );
} catch (Throwable $error) {
    trexgo_log_event('yandex_sync_failed', ['type' => get_class($error), 'code' => (string) $error->getCode()]);
    if (isset($config) && is_array($config)) {
        trexgo_notify_operational('Синхронизация заявок TrexGo не выполнена. Проверьте PHP error_log.', $config);
    }
    trexgo_cli_fail('Yandex Disk synchronization failed. See the PHP error log.');
} finally {
    @unlink($downloadPath);
    @unlink($uploadPath);
    if (is_resource($lock)) {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}
