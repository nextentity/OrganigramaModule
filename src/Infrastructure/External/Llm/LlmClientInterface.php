<?php

declare(strict_types=1);

namespace App\Infrastructure\External\Llm;

/**
 * Contrato de cliente para inferencia LLM con salida estructurada forzada (Structured Outputs).
 */
interface LlmClientInterface
{
    /**
     * @param string $userPrompt
     * @param string $systemInstruction
     * @return array<string, mixed>
     */
    public function generateStructuredTriage(string $userPrompt, string $systemInstruction): array;
}

