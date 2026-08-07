<?php

declare(strict_types=1);

use Shuchkin\SimpleXLSX;
use Shuchkin\SimpleXLSXGen;

final class XlsxLeadSheet
{
    public const STATUSES = ['new', 'contacted', 'qualified', 'won', 'lost', 'archived'];

    public const HEADERS = [
        'id', 'Дата создания', 'Время создания', 'Имя', 'Телефон', 'Email', 'Компания',
        'Комментарий клиента', 'Источник', 'Страница', 'Статус', 'Заметка',
        'Следующий шаг', 'Дата контакта', 'Ответственный', 'utm_source', 'utm_medium',
        'utm_campaign', 'utm_content', 'utm_term', 'yclid',
    ];

    private const DATE_ONLY_COLUMNS = ['Дата создания'];
    private const TIME_ONLY_COLUMNS = ['Время создания'];
    private const DATETIME_COLUMNS = ['Дата контакта'];
    private const RAW_DATE_COLUMNS = ['Дата создания', 'Время создания', 'Дата контакта'];

    /** @return list<array<string, string>> */
    public function read(string $path): array
    {
        $xlsx = SimpleXLSX::parseFile($path);
        if ($xlsx === false) {
            throw new RuntimeException('Cannot parse XLSX workbook');
        }
        $xlsx->setDateTimeFormat('Y-m-d H:i:s');
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
                $value = trim((string) ($row[$index] ?? ''));
                if (in_array($name, self::RAW_DATE_COLUMNS, true)) {
                    $value = $this->normalizeDateCell($name, $value);
                }
                $mapped[$name] = $value;
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
                function (string $header) use ($row): string {
                    $value = (string) ($row[$header] ?? '');
                    return in_array($header, self::RAW_DATE_COLUMNS, true) ? $value : $this->rawString($value);
                },
                self::HEADERS
            );
        }

        $xlsx = SimpleXLSXGen::fromArray($matrix, 'Заявки')
            ->setDefaultFont('Calibri')
            ->setDefaultFontSize(10)
            ->addSheet($this->legendRows(), 'Справка');
        if (!$xlsx->saveAs($path)) {
            throw new RuntimeException('Cannot write XLSX workbook');
        }
        @chmod($path, 0600);
    }

    /**
     * После записи Excel хранит даты как числа и отдаёт их обратно строкой в едином
     * для всей книги формате 'Y-m-d H:i:s' (SimpleXLSX::datetimeFormat), независимо от
     * того, была ли ячейка датой, временем или датой-временем. Здесь эта строка
     * разбирается и переформатируется обратно в то, что писал write() для конкретной
     * колонки — иначе на каждом холостом прогоне sync_yandex_disk.php строки не будут
     * совпадать побайтово, и файл будет перезаливаться без реальных изменений.
     */
    private function normalizeDateCell(string $column, string $value): string
    {
        if ($value === '') {
            return $value;
        }
        $parsed = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $value);
        if ($parsed === false) {
            return $value;
        }
        if (in_array($column, self::DATE_ONLY_COLUMNS, true)) {
            return $parsed->format('Y-m-d');
        }
        if (in_array($column, self::TIME_ONLY_COLUMNS, true)) {
            return $parsed->format('H:i:s');
        }
        return $parsed->format('Y-m-d H:i:s');
    }

    /** @return list<list<string>> */
    private function legendRows(): array
    {
        $columns = [
            ['id', 'скрипт', 'UUID заявки. Для новой ручной заявки оставить пустым — id появится сам после синхронизации.'],
            ['Дата создания', 'скрипт', 'Дата поступления заявки по Москве, ГГГГ-ММ-ДД.'],
            ['Время создания', 'скрипт', 'Время поступления заявки по Москве, ЧЧ:ММ:СС.'],
            ['Имя', 'скрипт / вручную', 'Имя клиента.'],
            ['Телефон', 'скрипт / вручную', 'Телефон клиента. Для новой ручной заявки обязателен.'],
            ['Email', 'скрипт / вручную', 'Email клиента.'],
            ['Компания', 'скрипт / вручную', 'Компания клиента.'],
            ['Комментарий клиента', 'скрипт / вручную', 'Комментарий из формы сайта или ваш при ручном занесении.'],
            ['Источник', 'скрипт / вручную', 'Например: сайт, avito, manual, знакомые.'],
            ['Страница', 'скрипт', 'Страница сайта, с которой пришла заявка.'],
            ['Статус', 'вручную', 'Один из статусов из списка ниже.'],
            ['Заметка', 'вручную', 'Рабочие заметки по заявке.'],
            ['Следующий шаг', 'вручную', 'Что делать дальше по этой заявке.'],
            ['Дата контакта', 'вручную', 'Дата и время контакта с клиентом, ГГГГ-ММ-ДД ЧЧ:ММ:СС.'],
            ['Ответственный', 'вручную', 'Кто ведёт заявку.'],
            ['utm_source', 'скрипт', 'Рекламная метка перехода.'],
            ['utm_medium', 'скрипт', 'Рекламная метка перехода.'],
            ['utm_campaign', 'скрипт', 'Рекламная метка перехода.'],
            ['utm_content', 'скрипт', 'Рекламная метка перехода.'],
            ['utm_term', 'скрипт', 'Рекламная метка перехода.'],
            ['yclid', 'скрипт', 'Идентификатор клика Яндекс.Директ.'],
        ];

        $rows = [array_map(fn (string $value): string => $this->rawString($value), ['Колонка', 'Кто заполняет', 'Что писать'])];
        foreach ($columns as $column) {
            $rows[] = array_map(fn (string $value): string => $this->rawString($value), $column);
        }

        $rows[] = ['', '', ''];
        $rows[] = array_map(
            fn (string $value): string => $this->rawString($value),
            ['Новая заявка вручную (Авито, знакомые)', '', 'Оставьте id пустым, заполните Имя/Телефон и остальные известные поля — на следующей синхронизации заявка появится в MySQL.']
        );
        $rows[] = array_map(
            fn (string $value): string => $this->rawString($value),
            ['Как убрать заявку из работы', '', 'Удаление строки не удаляет заявку из базы — она вернётся в конец списка. Ставьте статус lost или archived.']
        );

        $rows[] = ['', '', ''];
        $rows[] = array_map(fn (string $value): string => $this->rawString($value), ['Статус', 'Значение', '']);
        foreach (self::STATUSES as $status) {
            $rows[] = array_map(fn (string $value): string => $this->rawString($value), [$status, '', '']);
        }

        return $rows;
    }

    private function rawString(string $value): string
    {
        // SimpleXLSXGen иначе превращает +7999... в число и распознаёт часть
        // пользовательских строк как формулы/форматированные значения.
        return $value === '' ? '' : "\0" . $value;
    }
}
