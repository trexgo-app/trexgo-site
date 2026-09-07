<?php

declare(strict_types=1);

final class SheetLeadRows
{
    public const STATUSES = ['new', 'contacted', 'qualified', 'won', 'lost', 'archived'];
    public const SHEET_NAME = 'Заявки';
    public const LEGEND_SHEET_NAME = 'Справка';

    public const HEADERS = [
        'id', 'Дата создания', 'Время создания', 'Имя', 'Телефон', 'Email', 'Компания',
        'Комментарий клиента', 'Источник', 'Страница', 'Статус', 'Заметка',
        'Следующий шаг', 'Дата контакта', 'Ответственный', 'utm_source', 'utm_medium',
        'utm_campaign', 'utm_content', 'utm_term', 'yclid',
    ];

    /**
     * @param list<list<string>> $sheetRows строки из GoogleSheetsClient::readRange(),
     *   первая — заголовок (или пусто, если лист ещё не создан)
     * @return list<array<string, string>>
     */
    public function read(array $sheetRows): array
    {
        if ($sheetRows === []) {
            return [];
        }
        $header = array_map(static fn (string $value): string => trim($value), $sheetRows[0]);
        $header = array_pad(array_slice($header, 0, count(self::HEADERS)), count(self::HEADERS), '');
        if ($header !== self::HEADERS) {
            throw new RuntimeException('Google Sheet header was changed');
        }

        $result = [];
        foreach (array_slice($sheetRows, 1) as $row) {
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

    /**
     * @param list<array<string, string>> $rows
     * @return list<list<string>> строки для GoogleSheetsClient::writeRange(), включая заголовок
     */
    public function toSheetRows(array $rows): array
    {
        $matrix = [self::HEADERS];
        foreach ($rows as $row) {
            $matrix[] = array_map(
                static fn (string $header): string => (string) ($row[$header] ?? ''),
                self::HEADERS
            );
        }
        return $matrix;
    }

    /** @return list<list<string>> */
    public static function legendRows(): array
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

        $rows = [['Колонка', 'Кто заполняет', 'Что писать']];
        foreach ($columns as $column) {
            $rows[] = $column;
        }

        $rows[] = ['', '', ''];
        $rows[] = [
            'Новая заявка вручную (Авито, знакомые)', '',
            'Оставьте id пустым, заполните Имя/Телефон и остальные известные поля — на следующей синхронизации заявка появится в MySQL.',
        ];
        $rows[] = [
            'Как убрать заявку из работы', '',
            'Удаление строки не удаляет заявку из базы — она вернётся в конец списка. Ставьте статус lost или archived.',
        ];

        $rows[] = ['', '', ''];
        $rows[] = ['Статус', 'Значение', ''];
        foreach (self::STATUSES as $status) {
            $rows[] = [$status, '', ''];
        }

        return $rows;
    }
}
