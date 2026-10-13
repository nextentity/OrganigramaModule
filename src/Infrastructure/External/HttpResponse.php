<?php

declare(strict_types=1);

namespace App\Infrastructure\External;

/**
 * Representa una respuesta HTTP inmutable.
 */
final readonly class HttpResponse
{
    /**
     * @param array<string, string> $headers
     */
    public function __construct(
        public int $statusCode,
        public string $body,
        public array $headers = [],
    ) {
    }

    public function isSuccessful(): bool
    {
        return $this->statusCode >= 200 && $this->statusCode < 300;
    }

    /**
     * @return array<string, mixed>
     */
    public function json(): array
    {
        $decoded = json_decode($this->body, true);
        return is_array($decoded) ? $decoded : [];
    }
}

