<?php

declare(strict_types=1);

namespace App\Application\Service;

use App\Domain\Model\Ticket;
use App\Domain\Model\TriageResult;

/**
 * Contrato del servicio de aplicación encargado del triaging automatizado con IA.
 */
interface LlmTriageServiceInterface
{
    /**
     * Analiza el ticket mediante LLM con Structured Outputs y devuelve el TriageResult tipado.
     */
    public function triage(Ticket $ticket): TriageResult;
}

