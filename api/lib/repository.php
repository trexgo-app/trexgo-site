<?php

declare(strict_types=1);

/** @param array<string, mixed> $lead @return array{id:string, created:bool} */
function trexgo_store_submission(PDO $pdo, array $lead): array
{
    $table = $lead['form_kind'] === 'subscription' ? 'subscriptions' : 'leads';
    $existing = trexgo_find_submission($pdo, $table, (string) $lead['request_id']);
    if ($existing !== null) {
        return ['id' => $existing, 'created' => false];
    }

    $id = trexgo_uuid_v4();
    $now = trexgo_utc_now();

    try {
        if ($table === 'leads') {
            $sql = <<<'SQL'
                INSERT INTO leads (
                    id, request_id, created_at, updated_at, name, phone, email, company,
                    comment, source, page_url, referrer, utm_source, utm_medium,
                    utm_campaign, utm_content, utm_term, yclid, consent_at,
                    consent_text_version, status
                ) VALUES (
                    :id, :request_id, :created_at, :updated_at, :name, :phone, :email, :company,
                    :comment, :source, :page_url, :referrer, :utm_source, :utm_medium,
                    :utm_campaign, :utm_content, :utm_term, :yclid, :consent_at,
                    :consent_text_version, :status
                )
                SQL;
            $statement = $pdo->prepare($sql);
            $statement->execute([
                'id' => $id,
                'request_id' => $lead['request_id'],
                'created_at' => $now,
                'updated_at' => $now,
                'name' => $lead['name'],
                'phone' => $lead['phone'],
                'email' => $lead['email'],
                'company' => $lead['company'],
                'comment' => $lead['comment'],
                'source' => $lead['source'],
                'page_url' => $lead['page_url'],
                'referrer' => $lead['referrer'],
                'utm_source' => $lead['utm_source'],
                'utm_medium' => $lead['utm_medium'],
                'utm_campaign' => $lead['utm_campaign'],
                'utm_content' => $lead['utm_content'],
                'utm_term' => $lead['utm_term'],
                'yclid' => $lead['yclid'],
                'consent_at' => $now,
                'consent_text_version' => $lead['consent_text_version'],
                'status' => 'new',
            ]);
        } else {
            $sql = <<<'SQL'
                INSERT INTO subscriptions (
                    id, request_id, created_at, phone, email, page_url, source,
                    utm_source, utm_medium, utm_campaign, utm_content, utm_term,
                    yclid, consent_at, consent_text_version, status
                ) VALUES (
                    :id, :request_id, :created_at, :phone, :email, :page_url, :source,
                    :utm_source, :utm_medium, :utm_campaign, :utm_content, :utm_term,
                    :yclid, :consent_at, :consent_text_version, :status
                )
                SQL;
            $statement = $pdo->prepare($sql);
            $statement->execute([
                'id' => $id,
                'request_id' => $lead['request_id'],
                'created_at' => $now,
                'phone' => $lead['phone'],
                'email' => $lead['email'],
                'page_url' => $lead['page_url'],
                'source' => $lead['source'],
                'utm_source' => $lead['utm_source'],
                'utm_medium' => $lead['utm_medium'],
                'utm_campaign' => $lead['utm_campaign'],
                'utm_content' => $lead['utm_content'],
                'utm_term' => $lead['utm_term'],
                'yclid' => $lead['yclid'],
                'consent_at' => $now,
                'consent_text_version' => $lead['consent_text_version'],
                'status' => 'active',
            ]);
        }
    } catch (PDOException $error) {
        if ($error->getCode() === '23000') {
            $existing = trexgo_find_submission($pdo, $table, (string) $lead['request_id']);
            if ($existing !== null) {
                return ['id' => $existing, 'created' => false];
            }
        }
        throw $error;
    }

    return ['id' => $id, 'created' => true];
}

function trexgo_find_submission(PDO $pdo, string $table, string $requestId): ?string
{
    if (!in_array($table, ['leads', 'subscriptions'], true)) {
        throw new InvalidArgumentException('Unsupported submissions table');
    }

    $statement = $pdo->prepare("SELECT id FROM {$table} WHERE request_id = :request_id LIMIT 1");
    $statement->execute(['request_id' => $requestId]);
    $id = $statement->fetchColumn();

    return is_string($id) ? $id : null;
}

/** @param array<string, mixed> $config */
function trexgo_rate_limit(PDO $pdo, string $remoteAddress, array $config): bool
{
    $security = is_array($config['security'] ?? null) ? $config['security'] : [];
    $key = (string) ($security['rate_limit_key'] ?? '');
    if (preg_match('/^[0-9a-f]{64,}$/i', $key) !== 1) {
        throw new RuntimeException('Rate limit key is not configured');
    }

    $windowSeconds = max(60, (int) ($security['rate_limit_window_seconds'] ?? 900));
    $limit = max(1, (int) ($security['rate_limit_hits'] ?? 5));
    $windowStart = gmdate('Y-m-d H:i:s', intdiv(time(), $windowSeconds) * $windowSeconds);
    $ipHash = hash_hmac('sha256', $remoteAddress, $key);

    $pdo->beginTransaction();
    try {
        $upsert = $pdo->prepare(
            'INSERT INTO rate_limit (ip_hash, window_start, hits) VALUES (:ip_hash, :window_start, 1) '
            . 'ON DUPLICATE KEY UPDATE hits = hits + 1'
        );
        $upsert->execute(['ip_hash' => $ipHash, 'window_start' => $windowStart]);

        $select = $pdo->prepare(
            'SELECT hits FROM rate_limit WHERE ip_hash = :ip_hash AND window_start = :window_start'
        );
        $select->execute(['ip_hash' => $ipHash, 'window_start' => $windowStart]);
        $hits = (int) $select->fetchColumn();
        $pdo->commit();

        return $hits <= $limit;
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $error;
    }
}
