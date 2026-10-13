<?php

declare(strict_types=1);

namespace App\Infrastructure\External;

use RuntimeException;

/**
 * Cliente HTTP nativo basado en ext-curl con timeouts endurecidos.
 */
final class NativeCurlHttpClient implements HttpClientInterface
{
    public function __construct(
        private readonly int $timeoutSeconds = 15,
        private readonly int $connectTimeoutSeconds = 5,
    ) {
    }

    public function get(string $url, array $headers = []): HttpResponse
    {
        return $this->request('GET', $url, null, $headers);
    }

    public function post(string $url, array|string|null $body = null, array $headers = []): HttpResponse
    {
        return $this->request('POST', $url, $body, $headers);
    }

    public function put(string $url, array|string|null $body = null, array $headers = []): HttpResponse
    {
        return $this->request('PUT', $url, $body, $headers);
    }

    /**
     * @param array<string, mixed>|string|null $body
     * @param array<string, string> $headers
     */
    private function request(string $method, string $url, array|string|null $body, array $headers): HttpResponse
    {
        $ch = curl_init();
        if ($ch === false) {
            throw new RuntimeException('No se pudo inicializar cURL.');
        }

        $formattedHeaders = [];
        $hasContentType = false;
        foreach ($headers as $name => $value) {
            $formattedHeaders[] = "{$name}: {$value}";
            if (strcasecmp($name, 'Content-Type') === 0) {
                $hasContentType = true;
            }
        }

        $payload = null;
        if ($body !== null) {
            if (is_array($body)) {
                $payload = (string) json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                if (!$hasContentType) {
                    $formattedHeaders[] = 'Content-Type: application/json';
                }
            } else {
                $payload = $body;
            }
        }

        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, $this->timeoutSeconds);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $this->connectTimeoutSeconds);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $formattedHeaders);
        curl_setopt($ch, CURLOPT_USERAGENT, 'Support-AI-Triaging-Module/1.0 (PHP)');

        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            if ($payload !== null) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
            }
        } elseif ($method === 'PUT') {
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PUT');
            if ($payload !== null) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
            }
        } elseif ($method !== 'GET') {
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        }

        $responseBody = curl_exec($ch);
        $statusCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);

        if (curl_errno($ch)) {
            $errorMsg = curl_error($ch);
            curl_close($ch);
            throw new RuntimeException("Error en petición cURL a {$url}: {$errorMsg}");
        }

        curl_close($ch);

        return new HttpResponse(
            statusCode: $statusCode,
            body: is_string($responseBody) ? $responseBody : '',
        );
    }
}

