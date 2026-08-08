<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/api/lib/validation.php';

/**
 * Проблема с содержимым одной строки XLSX (не телефон, не статус, не найденный id).
 * Ловится точечно в основном цикле sync_yandex_disk.php — одна плохая строка
 * не должна ронять весь прогон синхронизации.
 */
final class TrexgoRowException extends RuntimeException
{
}

/** @param list<string> $validStatuses */
function trexgo_validate_status(string $status, array $validStatuses): string
{
    if (!in_array($status, $validStatuses, true)) {
        throw new TrexgoRowException('XLSX row has invalid status');
    }
    return $status;
}

/**
 * @param array<string, string> $row
 * @param list<string> $validStatuses
 * @return array{phone:string, email:string, status:string}
 */
function trexgo_validate_manual_row(array $row, array $validStatuses): array
{
    $phone = trexgo_normalize_phone($row['Телефон'] ?? '');
    $digits = preg_replace('/\D+/', '', $phone) ?? '';
    if (strlen($digits) < 10 || strlen($digits) > 15) {
        throw new TrexgoRowException('Manual XLSX row has invalid phone');
    }
    $email = strtolower(trim($row['Email'] ?? ''));
    if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
        throw new TrexgoRowException('Manual XLSX row has invalid email');
    }
    $status = trexgo_validate_status(trim($row['Статус'] ?? '') ?: 'new', $validStatuses);

    return ['phone' => $phone, 'email' => $email, 'status' => $status];
}
