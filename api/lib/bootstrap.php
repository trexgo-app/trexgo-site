<?php

declare(strict_types=1);

function trexgo_config_path(): string
{
    $override = getenv('TREXGO_LEADS_CONFIG');
    if (is_string($override) && $override !== '') {
        return $override;
    }

    return dirname(__DIR__, 3) . '/private/leads-config.php';
}

/** @return array<string, mixed> */
function trexgo_config(): array
{
    static $config;
    if (is_array($config)) {
        return $config;
    }

    $path = trexgo_config_path();
    if (!is_file($path)) {
        throw new RuntimeException('Leads configuration is unavailable');
    }

    $loaded = require $path;
    if (!is_array($loaded)) {
        throw new RuntimeException('Leads configuration is invalid');
    }

    $config = $loaded;
    return $config;
}

function trexgo_db(): PDO
{
    static $pdo;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $database = trexgo_config()['database'] ?? null;
    if (!is_array($database)) {
        throw new RuntimeException('Database configuration is invalid');
    }

    $pdo = new PDO(
        (string) ($database['dsn'] ?? ''),
        (string) ($database['username'] ?? ''),
        (string) ($database['password'] ?? ''),
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_STRINGIFY_FETCHES => false,
        ]
    );
    $pdo->exec("SET time_zone = '+00:00'");
    $pdo->exec("SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci");

    return $pdo;
}

function trexgo_uuid_v4(): string
{
    $bytes = random_bytes(16);
    $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
    $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
    $hex = bin2hex($bytes);

    return sprintf(
        '%s-%s-%s-%s-%s',
        substr($hex, 0, 8),
        substr($hex, 8, 4),
        substr($hex, 12, 4),
        substr($hex, 16, 4),
        substr($hex, 20, 12)
    );
}

function trexgo_utc_now(): string
{
    return gmdate('Y-m-d H:i:s');
}

/** @param array<string, scalar|null> $context */
function trexgo_log_event(string $event, array $context = []): void
{
    // В context разрешены только технические признаки. ПДн сюда не передавать.
    error_log('[trexgo-leads] ' . json_encode(
        ['event' => $event, 'context' => $context],
        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
    ));
}

/** @param array<string, mixed> $payload */
function trexgo_json_response(int $status, array $payload): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    header_remove('X-Powered-By');
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}
