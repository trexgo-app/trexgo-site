<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once dirname(__DIR__) . '/api/lib/bootstrap.php';

function trexgo_cli_fail(string $message, int $exitCode = 1): void
{
    fwrite(STDERR, $message . PHP_EOL);
    exit($exitCode);
}

function trexgo_private_directory(string $configKey): string
{
    $config = trexgo_config();
    $paths = is_array($config['paths'] ?? null) ? $config['paths'] : [];
    $path = (string) ($paths[$configKey] ?? '');
    if ($path === '') {
        throw new RuntimeException("Path {$configKey} is not configured");
    }
    if (!is_dir($path) && !mkdir($path, 0700, true) && !is_dir($path)) {
        throw new RuntimeException("Cannot create private directory for {$configKey}");
    }
    @chmod($path, 0700);

    return rtrim($path, '/\\');
}
