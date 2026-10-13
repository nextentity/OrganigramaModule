<?php

declare(strict_types=1);

namespace App\Infrastructure\External;

/**
 * Contrato de cliente HTTP para integraciones externas desacopladas.
 */
interface HttpClientInterface
{
    /**
     * @param array<string, string> $headers
     */
    public function get(string $url, array $headers = []): HttpResponse;

    /**
     * @param array<string, mixed>|string|null $body
     * @param array<string, string> $headers
     */
    public function post(string $url, array|string|null $body = null, array $headers = []): HttpResponse;

    /**
     * @param array<string, mixed>|string|null $body
     * @param array<string, string> $headers
     */
    public function put(string $url, array|string|null $body = null, array $headers = []): HttpResponse;
}

