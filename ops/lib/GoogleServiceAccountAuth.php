<?php

declare(strict_types=1);

final class GoogleServiceAccountAuth
{
    private const TOKEN_URL = 'https://oauth2.googleapis.com/token';

    private ?string $accessToken = null;
    private int $expiresAt = 0;

    /** @param list<string> $scopes */
    public function __construct(
        private readonly string $keyPath,
        private readonly array $scopes,
    ) {
    }

    public function token(): string
    {
        if ($this->accessToken !== null && time() < $this->expiresAt - 30) {
            return $this->accessToken;
        }

        $key = json_decode((string) file_get_contents($this->keyPath), true);
        if (!is_array($key)) {
            throw new RuntimeException('Cannot read Google service account key: ' . $this->keyPath);
        }
        $clientEmail = (string) ($key['client_email'] ?? '');
        $privateKey = (string) ($key['private_key'] ?? '');
        if ($clientEmail === '' || $privateKey === '') {
            throw new RuntimeException('Google service account key is missing client_email or private_key');
        }

        $now = time();
        $header = $this->base64UrlEncode((string) json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
        $claims = $this->base64UrlEncode((string) json_encode([
            'iss' => $clientEmail,
            'scope' => implode(' ', $this->scopes),
            'aud' => self::TOKEN_URL,
            'iat' => $now,
            'exp' => $now + 3600,
        ]));
        $signatureInput = $header . '.' . $claims;

        $signature = '';
        $signed = openssl_sign($signatureInput, $signature, $privateKey, OPENSSL_ALGO_SHA256);
        if (!$signed) {
            throw new RuntimeException('Cannot sign Google service account JWT');
        }
        $jwt = $signatureInput . '.' . $this->base64UrlEncode($signature);

        $curl = curl_init(self::TOKEN_URL);
        curl_setopt_array($curl, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_POSTFIELDS => http_build_query([
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion' => $jwt,
            ]),
        ]);
        $response = curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        curl_close($curl);

        if (!is_string($response) || $status !== 200) {
            throw new RuntimeException('Google OAuth token request failed with HTTP ' . $status);
        }
        $decoded = json_decode($response, true);
        $accessToken = is_array($decoded) ? (string) ($decoded['access_token'] ?? '') : '';
        if ($accessToken === '') {
            throw new RuntimeException('Google OAuth did not return an access token');
        }
        $expiresIn = is_array($decoded) ? (int) ($decoded['expires_in'] ?? 3600) : 3600;

        $this->accessToken = $accessToken;
        $this->expiresAt = $now + $expiresIn;

        return $this->accessToken;
    }

    private function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
