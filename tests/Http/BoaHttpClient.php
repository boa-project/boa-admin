<?php

declare(strict_types=1);

namespace BoA\Tests\Http;

/**
 * Minimal cookie-aware HTTP client for boa-admin endpoint flow tests.
 */
final class BoaHttpClient
{
    private string $baseUrl;
    private string $cookieFile;
    private ?string $secureToken = null;

    public function __construct(string $baseUrl)
    {
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->cookieFile = tempnam(sys_get_temp_dir(), 'boa_http_cookies_');
        if ($this->cookieFile === false) {
            throw new \RuntimeException('Unable to create cookie jar');
        }
    }

    public function __destruct()
    {
        if (is_string($this->cookieFile) && is_file($this->cookieFile)) {
            @unlink($this->cookieFile);
        }
    }

    public function baseUrl(): string
    {
        return $this->baseUrl;
    }

    public function isReachable(): bool
    {
        $response = $this->get(['get_action' => 'get_secure_token'], false);
        return $response['http_code'] > 0 && $response['http_code'] < 500;
    }

    public function getSecureToken(): string
    {
        $response = $this->get(['get_action' => 'get_secure_token'], false);
        if (!preg_match('/[a-f0-9]{32}/', $response['body'], $m)) {
            throw new \RuntimeException('Could not parse secure token from: ' . substr($response['body'], 0, 200));
        }
        $this->secureToken = $m[0];
        return $this->secureToken;
    }

    public function secureToken(): ?string
    {
        return $this->secureToken;
    }

    public function setSecureToken(string $token): void
    {
        $this->secureToken = $token;
    }

    /**
     * @param array<string, scalar|null> $query
     * @return array{http_code:int,body:string,content_type:string}
     */
    public function get(array $query = [], bool $withToken = true): array
    {
        return $this->request('GET', $query, [], $withToken);
    }

    /**
     * @param array<string, scalar|null> $query
     * @param array<string, scalar|null> $form
     * @return array{http_code:int,body:string,content_type:string}
     */
    public function post(array $query, array $form, bool $withToken = true): array
    {
        return $this->request('POST', $query, $form, $withToken);
    }

    /**
     * @param array<string, scalar|null> $query
     * @param array<string, scalar|null> $form
     * @return array{http_code:int,body:string,content_type:string}
     */
    private function request(string $method, array $query, array $form, bool $withToken): array
    {
        if ($withToken) {
            if ($this->secureToken === null) {
                $this->getSecureToken();
            }
            $query['secure_token'] = $this->secureToken;
        }

        $url = $this->baseUrl . '/index.php';
        if ($query !== []) {
            $url .= '?' . http_build_query($query);
        }

        $ch = curl_init($url);
        if ($ch === false) {
            throw new \RuntimeException('curl_init failed');
        }

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 90,
            CURLOPT_COOKIEJAR => $this->cookieFile,
            CURLOPT_COOKIEFILE => $this->cookieFile,
            CURLOPT_USERAGENT => 'boa-admin-http-tests/1.0',
            CURLOPT_HTTPHEADER => ['Expect:'],
        ]);

        if (strtoupper($method) === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($form));
        }

        $body = curl_exec($ch);
        $errno = curl_errno($ch);
        $error = curl_error($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $contentType = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        curl_close($ch);

        if ($errno !== 0) {
            return [
                'http_code' => 0,
                'body' => 'curl error: ' . $error,
                'content_type' => '',
            ];
        }

        return [
            'http_code' => $httpCode,
            'body' => is_string($body) ? $body : '',
            'content_type' => $contentType,
        ];
    }
}
