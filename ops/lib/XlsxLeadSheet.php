<?php

declare(strict_types=1);

use Shuchkin\SimpleXLSX;
use Shuchkin\SimpleXLSXGen;

final class XlsxLeadSheet
{
    public const HEADERS = [
        'id', 'Создана', 'Имя', 'Телефон', 'Email', 'Компания',
        'Комментарий клиента', 'Источник', 'Страница', 'utm_source', 'utm_medium',
        'utm_campaign', 'utm_content', 'utm_term', 'yclid', 'Статус', 'Заметка',
        'Следующий шаг', 'Дата контакта', 'Ответственный',
    ];

    /** @return list<array<string, string>> */
    public function read(string $path): array
    {
        $xlsx = SimpleXLSX::parseFile($path);
        if ($xlsx === false) {
            throw new RuntimeException('Cannot parse XLSX workbook');
        }
        $rows = $xlsx->rows(0);
        if ($rows === []) {
            return [];
        }
        $header = array_map(static fn (mixed $value): string => trim((string) $value), $rows[0]);
        if ($header !== self::HEADERS) {
            throw new RuntimeException('XLSX header was changed');
        }

        $result = [];
        foreach (array_slice($rows, 1) as $row) {
            $row = array_pad($row, count(self::HEADERS), '');
            $mapped = [];
            foreach (self::HEADERS as $index => $name) {
                $mapped[$name] = trim((string) ($row[$index] ?? ''));
            }
            if (array_filter($mapped, static fn (string $value): bool => $value !== '') !== []) {
                $result[] = $mapped;
            }
        }

        return $result;
    }

    /** @param list<array<string, string>> $rows */
    public function write(string $path, array $rows): void
    {
        $matrix = [array_map(fn (string $value): string => $this->rawString($value), self::HEADERS)];
        foreach ($rows as $row) {
            $matrix[] = array_map(
                fn (string $header): string => $this->rawString((string) ($row[$header] ?? '')),
                self::HEADERS
            );
        }

        $xlsx = SimpleXLSXGen::fromArray($matrix)
            ->setDefaultFont('Calibri')
            ->setDefaultFontSize(10);
        if (!$xlsx->saveAs($path)) {
            throw new RuntimeException('Cannot write XLSX workbook');
        }
        @chmod($path, 0600);
    }

    private function rawString(string $value): string
    {
        // SimpleXLSXGen иначе превращает +7999... в число и распознаёт часть
        // пользовательских строк как формулы/форматированные значения.
        return $value === '' ? '' : "\0" . $value;
    }
}
