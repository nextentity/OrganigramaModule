<?php

declare(strict_types=1);

namespace App\Infrastructure\External\Llm;

use App\Infrastructure\External\HttpClientInterface;
use App\Infrastructure\External\NativeCurlHttpClient;
use RuntimeException;

/**
 * Cliente de LLM con salidas estructuradas forzadas mediante JSON Schema.
 * Compatible con API OpenAI / Gemini / Ollama / Local AI y modo Mock para testing determinista.
 */
final class StructuredLlmClient implements LlmClientInterface
{
    private const OPENAI_JSON_SCHEMA = [
        'type'                 => 'object',
        'properties'           => [
            'summary'         => ['type' => 'string'],
            'type'            => ['type' => 'string', 'enum' => ['bug', 'operational_question', 'feature_request']],
            'severity'        => ['type' => 'string', 'enum' => ['low', 'medium', 'high', 'critical']],
            'repro_steps'     => ['type' => 'array', 'items' => ['type' => 'string']],
            'is_auto_fixable' => ['type' => 'boolean'],
            'suggested_area'  => ['type' => 'string'],
            'patch_hints'     => ['type' => 'string'],
            'canned_response' => ['type' => ['string', 'null']],
        ],
        'required'             => [
            'summary',
            'type',
            'severity',
            'repro_steps',
            'is_auto_fixable',
            'suggested_area',
            'patch_hints',
            'canned_response',
        ],
        'additionalProperties' => false,
    ];

    private const GEMINI_JSON_SCHEMA = [
        'type'       => 'OBJECT',
        'properties' => [
            'summary'         => ['type' => 'STRING'],
            'type'            => ['type' => 'STRING', 'enum' => ['bug', 'operational_question', 'feature_request']],
            'severity'        => ['type' => 'STRING', 'enum' => ['low', 'medium', 'high', 'critical']],
            'repro_steps'     => ['type' => 'ARRAY', 'items' => ['type' => 'STRING']],
            'is_auto_fixable' => ['type' => 'BOOLEAN'],
            'suggested_area'  => ['type' => 'STRING'],
            'patch_hints'     => ['type' => 'STRING'],
            'canned_response' => ['type' => 'STRING', 'nullable' => true],
        ],
        'required'   => [
            'summary',
            'type',
            'severity',
            'repro_steps',
            'is_auto_fixable',
            'suggested_area',
            'patch_hints',
        ],
    ];

    public function __construct(
        private readonly string $apiKey = '',
        private readonly string $model = 'gpt-4o-mini',
        private readonly string $baseUrl = 'https://api.openai.com/v1',
        private readonly ?HttpClientInterface $httpClient = null,
        private readonly string $provider = 'openai',
    ) {
    }

    public static function fromEnvironment(?HttpClientInterface $httpClient = null): self
    {
        $apiKey = (string) (getenv('LLM_API_KEY') ?: '');
        $provider = strtolower((string) (getenv('LLM_PROVIDER') ?: 'openai'));

        // Auto-detección inteligente: si la API key empieza con AIza, es Google Gemini
        if ($provider === 'openai' && str_starts_with($apiKey, 'AIza')) {
            $provider = 'gemini';
        }

        $defaultModel = $provider === 'gemini' ? 'gemini-3.8-flash' : 'gpt-4o-mini';
        $model = (string) (getenv('LLM_MODEL') ?: $defaultModel);
        if ($provider === 'gemini' && ($model === 'gpt-4o-mini' || $model === '')) {
            $model = 'gemini-3.8-flash';
        }

        $baseUrl = (string) (getenv('LLM_BASE_URL') ?: 'https://api.openai.com/v1');

        return new self(
            apiKey: $apiKey,
            model: $model,
            baseUrl: $baseUrl,
            httpClient: $httpClient ?? new NativeCurlHttpClient(),
            provider: $provider,
        );
    }

    public function generateStructuredTriage(string $userPrompt, string $systemInstruction): array
    {
        // 1. Modo Mock para testing automatizado o desarrollo sin API Key
        if ($this->apiKey === '' || $this->apiKey === 'mock' || $this->apiKey === 'test') {
            return $this->generateMockTriage($userPrompt);
        }

        $client = $this->httpClient ?? new NativeCurlHttpClient();

        if (strtolower($this->provider) === 'gemini') {
            return $this->callGeminiApi($client, $userPrompt, $systemInstruction);
        }

        return $this->callOpenAiApi($client, $userPrompt, $systemInstruction);
    }

    private function callOpenAiApi(HttpClientInterface $client, string $userPrompt, string $systemInstruction): array
    {
        $endpoint = rtrim($this->baseUrl, '/') . '/chat/completions';

        $payload = [
            'model'           => $this->model,
            'messages'        => [
                ['role' => 'system', 'content' => $systemInstruction],
                ['role' => 'user', 'content' => $userPrompt],
            ],
            'response_format' => [
                'type'        => 'json_schema',
                'json_schema' => [
                    'name'   => 'support_triage_result',
                    'strict' => true,
                    'schema' => self::OPENAI_JSON_SCHEMA,
                ],
            ],
            'temperature'     => 0.1,
        ];

        $maxRetries = 5;
        $lastException = null;

        for ($attempt = 1; $attempt <= $maxRetries; $attempt++) {
            try {
                $response = $client->post($endpoint, $payload, [
                    'Authorization' => "Bearer {$this->apiKey}",
                    'Content-Type'  => 'application/json',
                ]);

                if (!$response->isSuccessful()) {
                    if (in_array($response->statusCode, [429, 500, 502, 503, 504], true) && $attempt < $maxRetries) {
                        $delayMicros = (int) (pow(2, $attempt) * 1_000_000 + random_int(100_000, 500_000));
                        usleep($delayMicros);
                        continue;
                    }

                    $safeError = substr($response->body, 0, 300);
                    throw new RuntimeException(
                        "Error al invocar LLM ({$response->statusCode}): {$safeError}"
                    );
                }

                $data = $response->json();
                $rawContent = $data['choices'][0]['message']['content'] ?? null;

                if (!is_string($rawContent)) {
                    throw new RuntimeException('Respuesta del LLM vacía o malformada.');
                }

                $parsed = json_decode($rawContent, true);
                if (!is_array($parsed)) {
                    throw new RuntimeException('El contenido devuelto por el LLM no es un JSON válido.');
                }

                $this->validateSchemaKeys($parsed);

                return $parsed;
            } catch (Throwable $e) {
                $lastException = $e;
                $msg = $e->getMessage();
                $isNonRetryable = str_contains($msg, '400') || str_contains($msg, '401') || str_contains($msg, '403') || str_contains($msg, '404') || str_contains($msg, 'contrato');
                if (!$isNonRetryable && $attempt < $maxRetries) {
                    $delayMicros = (int) (pow(2, $attempt) * 1_000_000 + random_int(100_000, 500_000));
                    usleep($delayMicros);
                    continue;
                }
                throw $e;
            }
        }

        throw $lastException ?? new RuntimeException('Error desconocido al llamar al LLM.');
    }

    private function callGeminiApi(HttpClientInterface $client, string $userPrompt, string $systemInstruction): array
    {
        $model = $this->model;
        if ($model === 'gpt-4o-mini' || $model === '') {
            $model = 'gemini-3.8-flash';
        }

        $endpoint = sprintf(
            'https://generativelanguage.googleapis.com/v1beta/models/%s:generateContent',
            urlencode($model)
        );

        $payload = [
            'systemInstruction' => [
                'parts' => [['text' => $systemInstruction]],
            ],
            'contents'          => [
                [
                    'role'  => 'user',
                    'parts' => [['text' => $userPrompt]],
                ],
            ],
            'generationConfig'  => [
                'responseMimeType' => 'application/json',
                'responseSchema'   => self::GEMINI_JSON_SCHEMA,
                'temperature'      => 0.1,
            ],
        ];

        $maxRetries = 5;
        $lastException = null;

        for ($attempt = 1; $attempt <= $maxRetries; $attempt++) {
            try {
                $response = $client->post($endpoint, $payload, [
                    'Content-Type'   => 'application/json',
                    'x-goog-api-key' => $this->apiKey,
                ]);

                if (!$response->isSuccessful()) {
                    if (in_array($response->statusCode, [429, 500, 502, 503, 504], true) && $attempt < $maxRetries) {
                        $delayMicros = (int) (pow(2, $attempt) * 1_000_000 + random_int(100_000, 500_000));
                        usleep($delayMicros);
                        continue;
                    }

                    $safeError = substr($response->body, 0, 300);
                    throw new RuntimeException(
                        "Error al invocar Gemini LLM ({$response->statusCode}): {$safeError}"
                    );
                }

                $data = $response->json();
                $rawContent = $data['candidates'][0]['content']['parts'][0]['text'] ?? null;

                if (!is_string($rawContent)) {
                    throw new RuntimeException('Respuesta de Gemini vacía o malformada.');
                }

                $parsed = json_decode($rawContent, true);
                if (!is_array($parsed)) {
                    throw new RuntimeException('El contenido devuelto por Gemini no es un JSON válido.');
                }

                $this->validateSchemaKeys($parsed);

                return $parsed;
            } catch (Throwable $e) {
                $lastException = $e;
                $msg = $e->getMessage();
                $isNonRetryable = str_contains($msg, '400') || str_contains($msg, '401') || str_contains($msg, '403') || str_contains($msg, '404') || str_contains($msg, 'contrato');
                if (!$isNonRetryable && $attempt < $maxRetries) {
                    $delayMicros = (int) (pow(2, $attempt) * 1_000_000 + random_int(100_000, 500_000));
                    usleep($delayMicros);
                    continue;
                }
                throw $e;
            }
        }

        throw $lastException ?? new RuntimeException('Error desconocido al llamar a Gemini.');
    }

    /**
     * @param array<string, mixed> $parsed
     */
    private function validateSchemaKeys(array $parsed): void
    {
        $requiredKeys = ['summary', 'type', 'severity', 'repro_steps', 'is_auto_fixable', 'suggested_area', 'patch_hints'];
        foreach ($requiredKeys as $key) {
            if (!array_key_exists($key, $parsed)) {
                throw new RuntimeException("El JSON del LLM no cumple el contrato: falta la clave '{$key}'.");
            }
        }
    }

    /**
     * Generador de triage heurístico simulado para tests unitarios y CI.
     *
     * @return array<string, mixed>
     */
    private function generateMockTriage(string $prompt): array
    {
        $lower = strtolower($prompt);

        // Caso 1: Duda operativa
        if (str_contains($lower, 'cómo') || str_contains($lower, 'como') || str_contains($lower, 'donde') || str_contains($lower, 'ayuda') || str_contains($lower, 'question')) {
            return [
                'summary'         => 'Consulta sobre uso general de la plataforma',
                'type'            => 'operational_question',
                'severity'        => 'low',
                'repro_steps'     => [],
                'is_auto_fixable' => false,
                'suggested_area'  => 'docs/user_manual',
                'patch_hints'     => '',
                'canned_response' => 'Hola, puedes acceder a esta funcionalidad navegando a la sección de configuración de tu organigrama.',
            ];
        }

        // Caso 2: Bug auto-reparable (typo, frontend css, texto)
        if (str_contains($lower, 'typo') || str_contains($lower, 'traducción') || str_contains($lower, 'ortografía') || str_contains($lower, 'color') || str_contains($lower, 'autofix')) {
            return [
                'summary'         => 'Error tipográfico o de visualización detectado',
                'type'            => 'bug',
                'severity'        => 'low',
                'repro_steps'     => [
                    'Abrir la pantalla del organigrama',
                    'Verificar el texto o estilo en la cabecera',
                ],
                'is_auto_fixable' => true,
                'suggested_area'  => 'frontend/src/app/components/user-abm/user-abm.component.html',
                'patch_hints'     => 'Corregir el string en la plantilla HTML afectada.',
                'canned_response' => null,
            ];
        }

        // Caso 3: Bug estándar
        return [
            'summary'         => 'Fallo o error inesperado reportado por usuario',
            'type'            => 'bug',
            'severity'        => str_contains($lower, 'critico') || str_contains($lower, '500') ? 'critical' : 'medium',
            'repro_steps'     => [
                'Iniciar sesión en la aplicación',
                'Intentar ejecutar la acción indicada por el usuario',
                'Observar el error resultante',
            ],
            'is_auto_fixable' => false,
            'suggested_area'  => 'src/Application/Service',
            'patch_hints'     => 'Revisar logs del servidor y condiciones de borde en el servicio.',
            'canned_response' => null,
        ];
    }
}

