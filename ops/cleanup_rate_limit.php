<?php

declare(strict_types=1);

require_once __DIR__ . '/_cli.php';

try {
    $cutoff = gmdate('Y-m-d H:i:s', time() - 172800);
    $statement = trexgo_db()->prepare('DELETE FROM rate_limit WHERE window_start < :cutoff');
    $statement->execute(['cutoff' => $cutoff]);
    fwrite(STDOUT, 'Deleted ' . $statement->rowCount() . ' expired rate-limit rows' . PHP_EOL);
} catch (Throwable $error) {
    trexgo_log_event('rate_limit_cleanup_failed', ['type' => get_class($error)]);
    trexgo_cli_fail('Rate-limit cleanup failed. See the PHP error log.');
}
