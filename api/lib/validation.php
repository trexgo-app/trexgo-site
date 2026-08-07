<?php

declare(strict_types=1);

final class TrexgoValidationException extends RuntimeException
{
    public string $field;

    public function __construct(string $field, string $message)
    {
        $this->field = $field;
        parent::__construct($message);
    }
}

function trexgo_normalize_phone(string $value): string
{
    $digits = preg_replace('/\D+/', '', $value) ?? '';
    if (strlen($digits) === 10) {
        $digits = '7' . $digits;
    } elseif (strlen($digits) === 11 && $digits[0] === '8') {
        $digits = '7' . substr($digits, 1);
    }

    return $digits === '' ? '' : '+' . $digits;
}

function trexgo_is_uuid(string $value): bool
{
    return preg_match(
        '/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i',
        $value
    ) === 1;
}

function trexgo_text(mixed $value, int $maxLength): ?string
{
    if ($value === null) {
        return null;
    }
    if (!is_string($value) && !is_numeric($value)) {
        return null;
    }

    $value = trim((string) $value);
    if ($value === '') {
        return null;
    }

    return mb_substr($value, 0, $maxLength, 'UTF-8');
}

function trexgo_url(mixed $value): ?string
{
    $url = trexgo_text($value, 2048);
    if ($url === null) {
        return null;
    }

    $parts = parse_url($url);
    if (!is_array($parts) || !in_array(strtolower((string) ($parts['scheme'] ?? '')), ['http', 'https'], true)) {
        return null;
    }

    return $url;
}

/** @param array<string, mixed> $payload @param array<string, mixed> $config
 *  @return array<string, mixed>
 */
function trexgo_validate_submission(array $payload, array $config, ?int $now = null): array
{
    $requestId = strtolower(trim((string) ($payload['request_id'] ?? '')));
    if (!trexgo_is_uuid($requestId)) {
        throw new TrexgoValidationException('request_id', 'Некорректный идентификатор отправки');
    }

    $kind = (string) ($payload['form_kind'] ?? 'lead');
    if (!in_array($kind, ['lead', 'subscription'], true)) {
        throw new TrexgoValidationException('form_kind', 'Неизвестный тип формы');
    }

    $phone = trexgo_normalize_phone((string) ($payload['phone'] ?? ''));
    $phoneDigits = preg_replace('/\D+/', '', $phone) ?? '';
    if ($phone !== '' && (strlen($phoneDigits) < 10 || strlen($phoneDigits) > 15)) {
        throw new TrexgoValidationException('phone', 'Проверьте номер телефона');
    }

    $email = strtolower((string) (trexgo_text($payload['email'] ?? null, 254) ?? ''));
    if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
        throw new TrexgoValidationException('email', 'Проверьте адрес электронной почты');
    }

    if ($kind === 'lead' && $phone === '') {
        throw new TrexgoValidationException('phone', 'Укажите номер телефона');
    }
    if ($kind === 'subscription' && $phone === '' && $email === '') {
        throw new TrexgoValidationException('email', 'Укажите телефон или электронную почту');
    }

    $security = is_array($config['security'] ?? null) ? $config['security'] : [];
    $now ??= time();
    $startedAt = $payload['form_started_at'] ?? null;
    if (!is_numeric($startedAt)) {
        throw new TrexgoValidationException('form_started_at', 'Обновите страницу и попробуйте ещё раз');
    }
    $startedAt = (float) $startedAt;
    if ($startedAt > 100000000000.0) {
        $startedAt /= 1000;
    }
    $fillSeconds = $now - (int) $startedAt;
    $minFill = (int) ($security['min_fill_seconds'] ?? 2);
    $maxFill = (int) ($security['max_fill_seconds'] ?? 86400);
    if ($fillSeconds < $minFill || $fillSeconds > $maxFill) {
        throw new TrexgoValidationException('form_started_at', 'Обновите страницу и попробуйте ещё раз');
    }

    $honeypot = trexgo_text($payload['website'] ?? null, 200);

    return [
        'request_id' => $requestId,
        'form_kind' => $kind,
        'name' => trexgo_text($payload['name'] ?? null, 200),
        'phone' => $phone !== '' ? $phone : null,
        'email' => $email !== '' ? $email : null,
        'company' => trexgo_text($payload['company'] ?? null, 200),
        'comment' => trexgo_text($payload['comment'] ?? null, 5000),
        'source' => trexgo_text($payload['source'] ?? null, 64) ?? 'website',
        'page_url' => trexgo_url($payload['page_url'] ?? null),
        'referrer' => trexgo_url($payload['referrer'] ?? null),
        'utm_source' => trexgo_text($payload['utm_source'] ?? null, 255),
        'utm_medium' => trexgo_text($payload['utm_medium'] ?? null, 255),
        'utm_campaign' => trexgo_text($payload['utm_campaign'] ?? null, 255),
        'utm_content' => trexgo_text($payload['utm_content'] ?? null, 255),
        'utm_term' => trexgo_text($payload['utm_term'] ?? null, 255),
        'yclid' => trexgo_text($payload['yclid'] ?? null, 255),
        'honeypot' => $honeypot,
        'consent_text_version' => (string) ($config['consent_text_version'] ?? 'unknown'),
    ];
}
