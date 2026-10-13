<?php

declare(strict_types=1);

namespace App\Infrastructure\Http;

use App\Domain\Exception\DomainException;
use Throwable;

final class Router
{
    /** @var list<array{method: string, pattern: string, handler: callable}> */
    private array $routes = [];

    public function add(string $method, string $pathPattern, callable $handler): void
    {
        $regex = preg_replace('/\{([a-zA-Z0-9_]+)\}/', '(?P<$1>[a-zA-Z0-9_\-]+)', $pathPattern);
        $regex = '#^' . $regex . '$#';

        $this->routes[] = [
            'method'  => strtoupper($method),
            'pattern' => $regex,
            'handler' => $handler,
        ];
    }

    public function dispatch(string $method, string $uri): void
    {
        // Handling CORS preflight
        if ($method === 'OPTIONS') {
            http_response_code(204);
            header('Access-Control-Allow-Origin: *');
            header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
            header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');
            exit(0);
        }

        $path = parse_url($uri, PHP_URL_PATH) ?? '/';

        try {
            foreach ($this->routes as $route) {
                if ($route['method'] !== $method) {
                    continue;
                }

                if (preg_match($route['pattern'], $path, $matches) === 1) {
                    $params = array_filter($matches, static fn ($key) => is_string($key), ARRAY_FILTER_USE_KEY);
                    $params = array_map(static fn (mixed $v): int|string => is_numeric($v) ? (int) $v : (string) $v, $params);

                    call_user_func_array($route['handler'], $params);
                    return;
                }
            }

            JsonResponse::error('Ruta no encontrada', 404);
        } catch (DomainException $domainException) {
            JsonResponse::error(
                $domainException->getMessage(),
                $domainException->getStatusCode(),
                $domainException->getDetails()
            );
        } catch (Throwable $unhandledException) {
            error_log('[API Internal Error] ' . $unhandledException->getMessage() . "\n" . $unhandledException->getTraceAsString());
            JsonResponse::error(
                'Error interno del servidor: ' . $unhandledException->getMessage(),
                500
            );
        }
    }
}

