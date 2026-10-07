<?php

declare(strict_types=1);

namespace App\Infrastructure\Http;

final class JsonResponse
{
    /**
     * @param mixed $data
     * @param array<string, mixed> $meta
     */
    public static function success(mixed $data, int $statusCode = 200, array $meta = []): void
    {
        self::send([
            'status'    => 'success',
            'data'      => $data,
            'meta'      => $meta !== [] ? $meta : null,
            'timestamp' => date('c'),
        ], $statusCode);
    }

    /**
     * @param array<string, mixed> $details
     */
    public static function error(string $message, int $statusCode = 400, array $details = []): void
    {
        self::send([
            'status'    => 'error',
            'message'   => $message,
            'details'   => $details !== [] ? $details : null,
            'timestamp' => date('c'),
        ], $statusCode);
    }

    private static function send(array $payload, int $statusCode): void
    {
        if (!headers_sent()) {
            http_response_code($statusCode);
            header('Content-Type: application/json; charset=utf-8');
            header('Access-Control-Allow-Origin: *');
            header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
            header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');
        }

        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);

        if (!defined('TESTING_MODE') || !TESTING_MODE) {
            exit(0);
        }
    }
}
