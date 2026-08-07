<?php

declare(strict_types=1);

require_once __DIR__ . '/_cli.php';

$table = 'leads';
foreach (array_slice($argv, 1) as $argument) {
    if (str_starts_with($argument, '--table=')) {
        $table = substr($argument, 8);
    }
}
if (!in_array($table, ['leads', 'subscriptions'], true)) {
    trexgo_cli_fail('Allowed tables: leads, subscriptions', 2);
}

$columns = $table === 'leads'
    ? [
        'id', 'request_id', 'created_at', 'updated_at', 'name', 'phone', 'email',
        'company', 'comment', 'source', 'page_url', 'referrer', 'utm_source',
        'utm_medium', 'utm_campaign', 'utm_content', 'utm_term', 'yclid',
        'consent_at', 'consent_text_version', 'status', 'note', 'next_step',
        'contacted_at', 'owner', 'synced_at',
    ]
    : [
        'id', 'request_id', 'created_at', 'phone', 'email', 'page_url', 'source',
        'utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'utm_term',
        'yclid', 'consent_at', 'consent_text_version', 'status', 'unsubscribed_at',
    ];

try {
    $directory = trexgo_private_directory('exports');
    $stamp = gmdate('Ymd-His');
    $finalPath = "{$directory}/{$table}-{$stamp}.csv";
    $temporaryPath = $finalPath . '.tmp';
    $handle = fopen($temporaryPath, 'wb');
    if ($handle === false) {
        throw new RuntimeException('Cannot open export file');
    }
    @chmod($temporaryPath, 0600);

    fwrite($handle, "\xEF\xBB\xBF");
    fputcsv($handle, $columns, ';', '"', '\\');

    $query = trexgo_db()->query(
        'SELECT ' . implode(', ', $columns) . " FROM {$table} ORDER BY created_at, id"
    );
    $rows = 0;
    while ($row = $query->fetch(PDO::FETCH_ASSOC)) {
        $values = array_map(static fn (string $column): mixed => $row[$column] ?? null, $columns);
        fputcsv($handle, $values, ';', '"', '\\');
        $rows++;
    }
    fclose($handle);
    if (!rename($temporaryPath, $finalPath)) {
        throw new RuntimeException('Cannot finalize export file');
    }
    @chmod($finalPath, 0600);

    fwrite(STDOUT, "Exported {$rows} rows to " . basename($finalPath) . PHP_EOL);
} catch (Throwable $error) {
    if (isset($handle) && is_resource($handle)) {
        fclose($handle);
    }
    if (isset($temporaryPath) && is_file($temporaryPath)) {
        @unlink($temporaryPath);
    }
    trexgo_log_event('csv_export_failed', ['table' => $table, 'type' => get_class($error)]);
    trexgo_cli_fail('CSV export failed. See the PHP error log.');
}
