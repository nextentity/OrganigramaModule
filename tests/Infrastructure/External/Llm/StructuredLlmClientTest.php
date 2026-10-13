<?php

declare(strict_types=1);

namespace Tests\Infrastructure\External\Llm;

use App\Infrastructure\External\HttpClientInterface;
use App\Infrastructure\External\HttpResponse;
use App\Infrastructure\External\Llm\StructuredLlmClient;
use RuntimeException;
use Tests\Support\TestCase;

final class StructuredLlmClientTest extends TestCase
{
    public function testFallbackChainSwitchesModelOn503(): void
    {
        $calledUrls = [];

        $fakeHttpClient = new class($calledUrls) implements HttpClientInterface {
            /** @param array<int, string> $calledUrls */
            public function __construct(public array &$calledUrls)
            {
            }

            public function get(string $url, array $headers = []): HttpResponse
            {
                return new HttpResponse(200, '{}');
            }

            public function post(string $url, array|string|null $body = null, array $headers = []): HttpResponse
            {
                $this->calledUrls[] = $url;

                // Si se llama a gemini-3.8-flash, simular pico de saturación 503
                if (str_contains($url, 'gemini-3.8-flash')) {
                    return new HttpResponse(503, json_encode([
                        'error' => [
                            'code'    => 503,
                            'message' => 'This model is currently experiencing high demand.',
                            'status'  => 'UNAVAILABLE',
                        ],
                    ], JSON_THROW_ON_ERROR));
                }

                // El modelo de respaldo gemini-3.5-flash responde con éxito 200 OK
                $validTriage = [
                    'summary'         => 'Error en botón de navegación',
                    'type'            => 'bug',
                    'severity'        => 'low',
                    'repro_steps'     => ['Click en botón'],
                    'is_auto_fixable' => true,
                    'suggested_area'  => 'frontend/src/app',
                    'patch_hints'     => 'Ajustar selector',
                    'canned_response' => null,
                ];

                return new HttpResponse(200, json_encode([
                    'candidates' => [
                        [
                            'content' => [
                                'parts' => [
                                    ['text' => json_encode($validTriage, JSON_THROW_ON_ERROR)],
                                ],
                            ],
                        ],
                    ],
                ], JSON_THROW_ON_ERROR));
            }

            public function put(string $url, array|string|null $body = null, array $headers = []): HttpResponse
            {
                return new HttpResponse(200, '{}');
            }
        };

        $loggedMessages = [];
        $logger = static function (string $msg) use (&$loggedMessages): void {
            $loggedMessages[] = $msg;
        };

        $client = new StructuredLlmClient(
            apiKey: 'AIza_fake_test_key',
            model: 'gemini-3.8-flash',
            provider: 'gemini',
            fallbackModels: ['gemini-3.5-flash', 'gemini-2.0-flash'],
            httpClient: $fakeHttpClient,
            logger: $logger,
        );

        $result = $client->generateStructuredTriage('Fallo en botón', 'Instrucción de sistema');

        $this->assertSame('bug', $result['type']);
        $this->assertSame('Error en botón de navegación', $result['summary']);
        $this->assertTrue($result['is_auto_fixable']);

        // Se debe haber probado gemini-3.8-flash primero y luego conmutado a gemini-3.5-flash
        $this->assertTrue(count($calledUrls) >= 2);
        $this->assertTrue(str_contains($calledUrls[0], 'gemini-3.8-flash'));
        $this->assertTrue(str_contains(end($calledUrls), 'gemini-3.5-flash'));

        // Se debe haber emitido log de conmutación de fallback
        $this->assertTrue(count($loggedMessages) >= 1);
        $this->assertTrue(str_contains($loggedMessages[0], 'LLM Fallback'));
        $this->assertTrue(str_contains($loggedMessages[0], 'gemini-3.5-flash'));
    }

    public function testNonRetryableErrorDoesNotTriggerFallback(): void
    {
        $calledUrls = [];

        $fakeHttpClient = new class($calledUrls) implements HttpClientInterface {
            /** @param array<int, string> $calledUrls */
            public function __construct(public array &$calledUrls)
            {
            }

            public function get(string $url, array $headers = []): HttpResponse
            {
                return new HttpResponse(200, '{}');
            }

            public function post(string $url, array|string|null $body = null, array $headers = []): HttpResponse
            {
                $this->calledUrls[] = $url;

                // Simular 401 Unauthorized (error no recuperable cambiando de modelo)
                return new HttpResponse(401, json_encode([
                    'error' => ['code' => 401, 'message' => 'API Key invalid'],
                ], JSON_THROW_ON_ERROR));
            }

            public function put(string $url, array|string|null $body = null, array $headers = []): HttpResponse
            {
                return new HttpResponse(200, '{}');
            }
        };

        $client = new StructuredLlmClient(
            apiKey: 'AIza_bad_key',
            model: 'gemini-3.8-flash',
            provider: 'gemini',
            fallbackModels: ['gemini-3.5-flash', 'gemini-2.0-flash'],
            httpClient: $fakeHttpClient,
        );

        $threw = false;
        try {
            $client->generateStructuredTriage('Test prompt', 'System');
        } catch (RuntimeException $e) {
            $threw = true;
            $this->assertTrue(str_contains($e->getMessage(), '401'));
        }

        $this->assertTrue($threw, 'Debe lanzar excepción en error 401');
        // No debe haber llamado a los modelos de respaldo
        $this->assertSame(1, count($calledUrls));
        $this->assertTrue(str_contains($calledUrls[0], 'gemini-3.8-flash'));
    }

    public function testFallbackChainExhaustsWhenAllModelsFail(): void
    {
        $calledUrls = [];

        $fakeHttpClient = new class($calledUrls) implements HttpClientInterface {
            /** @param array<int, string> $calledUrls */
            public function __construct(public array &$calledUrls)
            {
            }

            public function get(string $url, array $headers = []): HttpResponse
            {
                return new HttpResponse(200, '{}');
            }

            public function post(string $url, array|string|null $body = null, array $headers = []): HttpResponse
            {
                $this->calledUrls[] = $url;

                // Todos los modelos devuelven 503
                return new HttpResponse(503, json_encode([
                    'error' => ['code' => 503, 'message' => 'Capacity exceeded everywhere'],
                ], JSON_THROW_ON_ERROR));
            }

            public function put(string $url, array|string|null $body = null, array $headers = []): HttpResponse
            {
                return new HttpResponse(200, '{}');
            }
        };

        $client = new StructuredLlmClient(
            apiKey: 'AIza_test_key',
            model: 'gemini-3.8-flash',
            provider: 'gemini',
            fallbackModels: ['gemini-3.5-flash'],
            httpClient: $fakeHttpClient,
        );

        $threw = false;
        try {
            $client->generateStructuredTriage('Test prompt', 'System');
        } catch (RuntimeException $e) {
            $threw = true;
            $this->assertTrue(str_contains($e->getMessage(), '503'));
        }

        $this->assertTrue($threw);
        // Debe haber probado ambos modelos en la cadena
        $this->assertTrue(count($calledUrls) >= 2);
        $this->assertTrue(str_contains($calledUrls[0], 'gemini-3.8-flash'));
        $this->assertTrue(str_contains(end($calledUrls), 'gemini-3.5-flash'));
    }
}
