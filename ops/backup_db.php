<?php

declare(strict_types=1);

require_once __DIR__ . '/_cli.php';

try {
    $config = trexgo_config();
    $database = is_array($config['database'] ?? null) ? $config['database'] : [];
    $binary = (string) ($config['mysqldump_path'] ?? '/usr/bin/mysqldump');
    $databaseName = (string) ($database['name'] ?? '');
    $username = (string) ($database['username'] ?? '');
    $password = (string) ($database['password'] ?? '');
    $socket = (string) ($database['socket'] ?? '');
    $host = (string) ($database['host'] ?? '');

    if (!is_file($binary) || !preg_match('/^[A-Za-z0-9_]+$/', $databaseName) || $username === '') {
        throw new RuntimeException('Backup configuration is invalid');
    }

    $directory = trexgo_private_directory('backups');
    $finalPath = $directory . '/trexgo-' . gmdate('Ymd-His') . '.sql.gz';
    $temporaryPath = $finalPath . '.tmp';
    $gzip = gzopen($temporaryPath, 'wb9');
    if ($gzip === false) {
        throw new RuntimeException('Cannot open backup file');
    }
    @chmod($temporaryPath, 0600);

    $command = [
        $binary,
        '--single-transaction',
        '--quick',
        '--skip-lock-tables',
        '--default-character-set=utf8mb4',
        '--user=' . $username,
    ];
    if ($socket !== '') {
        $command[] = '--socket=' . $socket;
    } elseif ($host !== '') {
        $command[] = '--host=' . $host;
    }
    $command[] = '--databases';
    $command[] = $databaseName;

    $process = proc_open(
        $command,
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        null,
        ['MYSQL_PWD' => $password]
    );
    if (!is_resource($process)) {
        throw new RuntimeException('Cannot start mysqldump');
    }

    while (!feof($pipes[1])) {
        $chunk = fread($pipes[1], 65536);
        if ($chunk === false || ($chunk !== '' && gzwrite($gzip, $chunk) === false)) {
            throw new RuntimeException('Cannot write backup stream');
        }
    }
    fclose($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[2]);
    $exitCode = proc_close($process);
    gzclose($gzip);

    if ($exitCode !== 0) {
        throw new RuntimeException('mysqldump failed: ' . mb_substr((string) $stderr, 0, 200));
    }
    if (!rename($temporaryPath, $finalPath)) {
        throw new RuntimeException('Cannot finalize backup file');
    }
    @chmod($finalPath, 0600);

    fwrite(STDOUT, 'Backup created: ' . basename($finalPath) . PHP_EOL);
} catch (Throwable $error) {
    if (isset($gzip) && is_resource($gzip)) {
        gzclose($gzip);
    }
    if (isset($temporaryPath) && is_file($temporaryPath)) {
        @unlink($temporaryPath);
    }
    trexgo_log_event('database_backup_failed', ['type' => get_class($error)]);
    trexgo_cli_fail('Database backup failed. See the PHP error log.');
}
