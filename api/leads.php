<?php

declare(strict_types=1);

require_once __DIR__ . '/lib/bootstrap.php';
require_once __DIR__ . '/lib/validation.php';
require_once __DIR__ . '/lib/repository.php';
require_once __DIR__ . '/lib/notifications.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    trexgo_json_response(405, ['ok' => false, 'error' => 'method_not_allowed']);
}

$contentType = strtolower((string) ($_SERVER['CONTENT_TYPE'] ?? ''));
if (!str_starts_with($contentType, 'application/json')) {
    trexgo_json_response(415, ['ok' => false, 'error' => 'json_required']);
}

$contentLength = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);
if ($contentLength > 32768) {
    trexgo_json_response(413, ['ok' => false, 'error' => 'payload_too_large']);
}

try {
    $config = trexgo_config();
} catch (Throwable $error) {
    trexgo_log_event('config_unavailable', ['type' => get_class($error)]);
    trexgo_json_response(503, ['ok' => false, 'error' => 'service_unavailable']);
}

$origin = (string) ($_SERVER['HTTP_ORIGIN'] ?? '');
if ($origin !== '') {
    $originHost = strtolower((string) parse_url($origin, PHP_URL_HOST));
    $allowedHosts = is_array($config['security']['allowed_hosts'] ?? null)
        ? $config['security']['allowed_hosts']
        : [];
    if (!in_array($originHost, $allowedHosts, true)) {
        trexgo_json_response(403, ['ok' => false, 'error' => 'origin_not_allowed']);
    }
}

$raw = file_get_contents('php://input');
$payload = is_string($raw) ? json_decode($raw, true) : null;
if (!is_array($payload)) {
    trexgo_json_response(400, ['ok' => false, 'error' => 'invalid_json']);
}

try {
    $lead = trexgo_validate_submission($payload, $config);
} catch (TrexgoValidationException $error) {
    trexgo_json_response(422, [
        'ok' => false,
        'error' => 'validation_failed',
        'field' => $error->field,
        'message' => $error->getMessage(),
    ]);
}

if ($lead['honeypot'] !== null) {
    trexgo_json_response(202, ['ok' => true]);
}
unset($lead['honeypot']);

$remoteAddress = (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
$databaseError = null;
try {
    $pdo = trexgo_db();
    if (!trexgo_rate_limit($pdo, $remoteAddress, $config)) {
        trexgo_json_response(429, ['ok' => false, 'error' => 'rate_limited']);
    }
    $stored = trexgo_store_submission($pdo, $lead);
} catch (Throwable $error) {
    $databaseError = $error;
}

if ($databaseError === null) {
    if ($stored['created']) {
        try {
            $channels = trexgo_notify($lead, $config, false);
        } catch (Throwable $error) {
            $channels = ['telegram' => false, 'mail' => false];
            trexgo_log_event('notification_exception_after_store', ['type' => get_class($error)]);
        }
        if (!$channels['telegram'] && !$channels['mail']) {
            trexgo_log_event('notification_failed_after_store');
        }
    }

    trexgo_json_response(200, [
        'ok' => true,
        'id' => $stored['id'],
        'stored' => 'database',
    ]);
}

trexgo_log_event('database_unavailable', [
    'type' => get_class($databaseError),
    'code' => (string) $databaseError->getCode(),
]);
try {
    $channels = trexgo_notify($lead, $config, true);
} catch (Throwable $error) {
    $channels = ['telegram' => false, 'mail' => false];
    trexgo_log_event('notification_exception_without_store', ['type' => get_class($error)]);
}
if ($channels['telegram'] || $channels['mail']) {
    trexgo_json_response(202, [
        'ok' => true,
        'stored' => 'notification',
    ]);
}

$fallback = is_array($config['fallback'] ?? null) ? $config['fallback'] : [];
trexgo_log_event('submission_unavailable');
trexgo_json_response(503, [
    'ok' => false,
    'error' => 'service_unavailable',
    'phone' => (string) ($fallback['phone'] ?? ''),
    'whatsapp_url' => (string) ($fallback['whatsapp_url'] ?? ''),
]);
