<?php

declare(strict_types=1);

require_once __DIR__ . '/_cli.php';

try {
    $pdo = trexgo_db();
    $pdo->exec(<<<'SQL'
        CREATE TABLE IF NOT EXISTS schema_migrations (
            migration VARCHAR(255) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            applied_at DATETIME NOT NULL,
            PRIMARY KEY (migration)
        ) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci
        SQL);

    $migrationDirectory = dirname(__DIR__) . '/db/migrations';
    $files = glob($migrationDirectory . '/*.sql') ?: [];
    sort($files, SORT_STRING);

    $hasMigration = $pdo->prepare('SELECT 1 FROM schema_migrations WHERE migration = :migration');
    $recordMigration = $pdo->prepare(
        'INSERT INTO schema_migrations (migration, applied_at) VALUES (:migration, :applied_at)'
    );

    $applied = 0;
    foreach ($files as $file) {
        $name = basename($file);
        $hasMigration->execute(['migration' => $name]);
        if ($hasMigration->fetchColumn() !== false) {
            continue;
        }

        $sql = file_get_contents($file);
        if (!is_string($sql)) {
            throw new RuntimeException("Cannot read migration {$name}");
        }
        $statements = preg_split('/;\s*(?:\R|$)/', $sql) ?: [];
        foreach ($statements as $statement) {
            $statement = trim($statement);
            if ($statement !== '') {
                $pdo->exec($statement);
            }
        }
        $recordMigration->execute(['migration' => $name, 'applied_at' => trexgo_utc_now()]);
        fwrite(STDOUT, "Applied {$name}" . PHP_EOL);
        $applied++;
    }

    fwrite(STDOUT, $applied === 0 ? "No pending migrations" . PHP_EOL : "Migrations complete" . PHP_EOL);
} catch (Throwable $error) {
    trexgo_log_event('migration_failed', ['type' => get_class($error), 'code' => (string) $error->getCode()]);
    trexgo_cli_fail('Migration failed. See the PHP error log.');
}
