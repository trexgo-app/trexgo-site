<?php

declare(strict_types=1);

final class GoogleSheetsTransientException extends RuntimeException
{
}

final class GoogleSheetsClient
{
    private const SHEETS_BASE = 'https://sheets.googleapis.com/v4/spreadsheets';
    private const DRIVE_BASE = 'https://www.googleapis.com/drive/v3/files';
    private const RETRY_ATTEMPTS = 5;
    private const RETRY_BASE_DELAY_SECONDS = 4;

    public function __construct(private readonly GoogleServiceAccountAuth $auth)
    {
    }

    /**
     * Сеть до внешних API с Макхоста периодически рвётся на уровне TCP (curl отдаёт
     * HTTP 0), не только до Яндекс.Диска — тот же паттерн повторов, что в
     * YandexDiskClient::withRetry(), переносим сюда без изменений по сути.
     */
    private function withRetry(callable $attempt): mixed
    {
        $lastError = null;
        for ($try = 1; $try <= self::RETRY_ATTEMPTS; $try++) {
            try {
                return $attempt();
            } catch (GoogleSheetsTransientException $error) {
                $lastError = $error;
                if ($try < self::RETRY_ATTEMPTS) {
                    sleep(self::RETRY_BASE_DELAY_SECONDS * (2 ** ($try - 1)));
                }
            }
        }
        throw $lastError;
    }

    /** @return list<list<string>> */
    public function readRange(string $spreadsheetId, string $range): array
    {
        [, $body] = $this->request(
            'GET',
            self::SHEETS_BASE . '/' . rawurlencode($spreadsheetId) . '/values/' . rawurlencode($range)
                . '?' . http_build_query(['valueRenderOption' => 'UNFORMATTED_VALUE', 'dateTimeRenderOption' => 'FORMATTED_STRING'])
        );

        $values = $body['values'] ?? [];
        return is_array($values) ? array_map(
            static fn (mixed $row): array => array_map(static fn (mixed $cell): string => trim((string) $cell), (array) $row),
            $values
        ) : [];
    }

    /** @param list<list<string>> $rows */
    public function writeRange(string $spreadsheetId, string $range, array $rows): void
    {
        $this->request(
            'PUT',
            self::SHEETS_BASE . '/' . rawurlencode($spreadsheetId) . '/values/' . rawurlencode($range)
                . '?' . http_build_query(['valueInputOption' => 'RAW']),
            ['values' => $rows]
        );
    }

    public function clearRange(string $spreadsheetId, string $range): void
    {
        $this->request(
            'POST',
            self::SHEETS_BASE . '/' . rawurlencode($spreadsheetId) . '/values/' . rawurlencode($range) . ':clear'
        );
    }

    /** @param array<string, mixed> $body */
    public function createSpreadsheet(array $body): string
    {
        [, $response] = $this->request('POST', self::SHEETS_BASE, $body);
        $id = (string) ($response['spreadsheetId'] ?? '');
        if ($id === '') {
            throw new RuntimeException('Google Sheets did not return a spreadsheetId');
        }
        return $id;
    }

    public function shareWithEditor(string $fileId, string $email): void
    {
        $this->request(
            'POST',
            self::DRIVE_BASE . '/' . rawurlencode($fileId) . '/permissions',
            ['role' => 'writer', 'type' => 'user', 'emailAddress' => $email]
        );
    }

    public function fileModifiedTime(string $fileId): string
    {
        [, $response] = $this->request(
            'GET',
            self::DRIVE_BASE . '/' . rawurlencode($fileId) . '?' . http_build_query(['fields' => 'modifiedTime'])
        );
        return (string) ($response['modifiedTime'] ?? '');
    }

    /**
     * @param array<string, mixed>|null $jsonBody
     * @return array{0:int,1:array<string,mixed>}
     */
    private function request(string $method, string $url, ?array $jsonBody = null): array
    {
        $curl = curl_init($url);
        $headers = [
            'Accept: application/json',
            'Authorization: Bearer ' . $this->auth->token(),
        ];
        $options = [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_HTTPHEADER => $headers,
        ];
        if ($jsonBody !== null) {
            $options[CURLOPT_HTTPHEADER][] = 'Content-Type: application/json';
            $options[CURLOPT_POSTFIELDS] = (string) json_encode($jsonBody);
        }
        curl_setopt_array($curl, $options);

        try {
            return $this->withRetry(function () use ($curl): array {
                $response = curl_exec($curl);
                $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
                $curlCode = curl_errno($curl);
                $curlMessage = curl_error($curl);

                if (!is_string($response) || $status < 200 || $status >= 300) {
                    $message = sprintf(
                        'Google API request failed with HTTP %d, cURL %d: %s',
                        $status,
                        $curlCode,
                        $curlMessage !== '' ? $curlMessage : ($response !== false ? (string) $response : 'no transport details')
                    );
                    if ($curlCode !== 0 || $status === 0 || $status === 429 || $status >= 500) {
                        throw new GoogleSheetsTransientException($message);
                    }
                    throw new RuntimeException($message);
                }

                $decoded = $response === '' ? [] : json_decode($response, true);
                if (!is_array($decoded)) {
                    throw new GoogleSheetsTransientException('Google API returned invalid JSON');
                }

                return [$status, $decoded];
            });
        } finally {
            curl_close($curl);
        }
    }
}
