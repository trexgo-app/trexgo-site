<?php

declare(strict_types=1);

final class YandexDiskClient
{
    private const API_BASE = 'https://cloud-api.yandex.net/v1/disk/resources';
    private const RETRY_ATTEMPTS = 3;
    private const RETRY_DELAY_SECONDS = 1;
    private ?string $accessToken = null;
    /** @var array<string, mixed> */
    private array $config;

    /**
     * Сеть до Яндекс.Диска с Макхоста иногда рвётся на уровне TCP (curl отдаёт HTTP 0),
     * а не отвечает ошибкой API — повтор через секунду в норме решает это без шума в Telegram.
     */
    private function withRetry(callable $attempt): mixed
    {
        $lastError = null;
        for ($try = 1; $try <= self::RETRY_ATTEMPTS; $try++) {
            try {
                return $attempt();
            } catch (RuntimeException $error) {
                $lastError = $error;
                if ($try < self::RETRY_ATTEMPTS) {
                    sleep(self::RETRY_DELAY_SECONDS);
                }
            }
        }
        throw $lastError;
    }

    /** @param array<string, mixed> $config */
    public function __construct(array $config)
    {
        $this->config = $config;
    }

    /** @return array{modified:string}|null */
    public function metadata(string $path): ?array
    {
        [$status, $body] = $this->requestJson(
            'GET',
            self::API_BASE . '?' . http_build_query(
                ['path' => $path, 'fields' => 'modified'],
                '',
                '&',
                PHP_QUERY_RFC3986
            ),
            null,
            [200, 404]
        );
        if ($status === 404) {
            return null;
        }

        return ['modified' => (string) ($body['modified'] ?? '')];
    }

    public function download(string $path, string $destination): void
    {
        [, $link] = $this->requestJson(
            'GET',
            self::API_BASE . '/download?' . http_build_query(['path' => $path], '', '&', PHP_QUERY_RFC3986)
        );
        $href = (string) ($link['href'] ?? '');
        if ($href === '') {
            throw new RuntimeException('Yandex Disk did not provide a download link');
        }

        $this->withRetry(function () use ($href, $destination): void {
            $handle = fopen($destination, 'wb');
            if ($handle === false) {
                throw new RuntimeException('Cannot open XLSX destination');
            }
            $curl = curl_init($href);
            curl_setopt_array($curl, [
                CURLOPT_FILE => $handle,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_CONNECTTIMEOUT => 5,
                CURLOPT_TIMEOUT => 30,
            ]);
            $ok = curl_exec($curl);
            $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
            curl_close($curl);
            fclose($handle);
            if ($ok !== true || $status < 200 || $status >= 300) {
                @unlink($destination);
                throw new RuntimeException('Yandex Disk download failed');
            }
        });
    }

    public function upload(string $path, string $source): void
    {
        [, $link] = $this->requestJson(
            'GET',
            self::API_BASE . '/upload?' . http_build_query(
                ['path' => $path, 'overwrite' => 'true'],
                '',
                '&',
                PHP_QUERY_RFC3986
            )
        );
        $href = (string) ($link['href'] ?? '');
        if ($href === '') {
            throw new RuntimeException('Yandex Disk did not provide an upload link');
        }

        $this->withRetry(function () use ($href, $source): void {
            $handle = fopen($source, 'rb');
            if ($handle === false) {
                throw new RuntimeException('Cannot open XLSX source');
            }
            $curl = curl_init($href);
            curl_setopt_array($curl, [
                CURLOPT_UPLOAD => true,
                CURLOPT_INFILE => $handle,
                CURLOPT_INFILESIZE => filesize($source),
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_CONNECTTIMEOUT => 5,
                CURLOPT_TIMEOUT => 30,
            ]);
            $ok = curl_exec($curl);
            $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
            curl_close($curl);
            fclose($handle);
            if ($ok === false || $status < 200 || $status >= 300) {
                throw new RuntimeException('Yandex Disk upload failed');
            }
        });
    }

    /** @return array{0:int,1:array<string,mixed>} */
    private function requestJson(
        string $method,
        string $url,
        ?array $form = null,
        array $allowedStatuses = [200]
    ): array {
        $curl = curl_init($url);
        $headers = ['Accept: application/json'];
        if (!str_contains($url, 'oauth.yandex.com')) {
            $headers[] = 'Authorization: OAuth ' . $this->token();
        }
        $options = [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_HTTPHEADER => $headers,
        ];
        if ($form !== null) {
            $options[CURLOPT_POSTFIELDS] = http_build_query($form);
        }
        curl_setopt_array($curl, $options);
        try {
            return $this->withRetry(function () use ($curl, $allowedStatuses): array {
                $response = curl_exec($curl);
                $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);

                if (!is_string($response) || !in_array($status, $allowedStatuses, true)) {
                    throw new RuntimeException("Yandex API request failed with HTTP {$status}");
                }
                $decoded = json_decode($response, true);
                if (!is_array($decoded)) {
                    throw new RuntimeException('Yandex API returned invalid JSON');
                }

                return [$status, $decoded];
            });
        } finally {
            curl_close($curl);
        }
    }

    private function token(): string
    {
        if ($this->accessToken !== null) {
            return $this->accessToken;
        }

        $clientId = (string) ($this->config['client_id'] ?? '');
        $clientSecret = (string) ($this->config['client_secret'] ?? '');
        $refreshToken = (string) ($this->config['refresh_token'] ?? '');
        if ($clientId === '' || $clientSecret === '' || $refreshToken === '') {
            throw new RuntimeException('Yandex Disk OAuth is not configured');
        }

        [, $response] = $this->requestJson(
            'POST',
            'https://oauth.yandex.com/token',
            [
                'grant_type' => 'refresh_token',
                'refresh_token' => $refreshToken,
                'client_id' => $clientId,
                'client_secret' => $clientSecret,
            ]
        );
        $token = (string) ($response['access_token'] ?? '');
        if ($token === '') {
            throw new RuntimeException('Yandex OAuth did not return an access token');
        }
        $this->accessToken = $token;

        return $token;
    }
}
