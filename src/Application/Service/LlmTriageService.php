<?php

declare(strict_types=1);

namespace App\Application\Service;

use App\Domain\Model\Ticket;
use App\Domain\Model\TriageResult;
use App\Infrastructure\External\Llm\LlmClientInterface;

/**
 * Servicio de aplicación que implementa el Triage de soporte técnico con LLM.
 * Formula directivas de ingeniería de prompt y mapea el JSON devuelto al Value Object TriageResult.
 */
final class LlmTriageService implements LlmTriageServiceInterface
{
    private const SYSTEM_INSTRUCTION = <<<'PROMPT'
Eres un Senior Staff Software Engineer y Lead Support Triage AI para una plataforma empresarial PHP / SQLite / Angular de gestión de organigramas y usuarios multi-tenant.

Tu tarea es clasificar tickets de soporte provenientes de canales externos (WhatsApp, formularios web, helpdesk) y responder estrictamente en el JSON Schema solicitado:

Reglas de Negocio para la Clasificación:
1. TYPE:
   - "operational_question": El usuario pregunta cómo hacer algo, dónde encontrar una opción, solicita permisos o tiene dudas conceptuales. En este caso:
     * Genera un "canned_response" claro, profesional y empático resolviendo la duda.
     * "is_auto_fixable" DEBE ser false.
     * "repro_steps" puede estar vacío.
   - "bug": Un comportamiento inesperado, error 500, pantalla blanca, fallo de base de datos, botón roto, typo o inconsistencia de datos.
     * "canned_response" debe ser null.
     * "repro_steps" debe listar los pasos ordenados para replicar el fallo.
     * Evalúa si es auto-reparable en "is_auto_fixable".
   - "feature_request": El usuario pide una función que actualmente no existe en el sistema.
     * "is_auto_fixable" DEBE ser false.

2. IS_AUTO_FIXABLE (Criterio Estricto):
   - Solo marca "is_auto_fixable": true si el bug es trivial y seguro de reparar automáticamente sin romper arquitectura:
     * Typos o errores ortográficos en textos, traducciones o etiquetas HTML.
     * Validaciones regex o mensajes de error puntuales.
     * Correcciones menores de CSS/SCSS o estilos de frontend.
   - Si requiere cambios de base de datos, lógica de negocio multi-tenant o refactor de arquitectura, "is_auto_fixable" DEBE ser false.
   - Si "is_auto_fixable": true, proporciona instrucciones precisas en "patch_hints" y la ruta relativa real en "suggested_area".
     * Estructura de archivos del proyecto para "suggested_area":
       Frontend Angular:
       - frontend/src/app/components/user-abm/user-abm.component.html (ABM de personal/usuarios, inputs de búsqueda y tabla)
       - frontend/src/app/components/user-abm/user-abm.component.ts
       - frontend/src/app/components/org-chart/org-chart.component.html (Visualización de Organigrama jerárquico)
       - frontend/src/app/components/org-chart/org-chart.component.ts
       - frontend/src/app/app.component.html
       Backend PHP:
       - src/Application/Service/OrganigramaService.php
       - src/Infrastructure/Http/OrganigramaController.php
     * Formato en "patch_hints":
       - Sustituciones: reemplazar 'TEXTO_A_BUSCAR' por 'TEXTO_NUEVO'
       - Remociones: remover 'TEXTO_A_ELIMINAR' o remover el atributo placeholder

3. SEVERITY:
   - "critical": Bloqueo total del sistema, fallo general de autenticación o pérdida de datos.
   - "high": Funcionalidad principal rota sin workaround obvio.
   - "medium": Error en funcionalidad secundaria con workaround.
   - "low": Typos, cosmético, dudas operativas o solicitudes menores.
PROMPT;

    public function __construct(
        private readonly LlmClientInterface $llmClient,
    ) {
    }

    public function triage(Ticket $ticket): TriageResult
    {
        $userPrompt = $this->buildPrompt($ticket);
        $rawTriageData = $this->llmClient->generateStructuredTriage($userPrompt, self::SYSTEM_INSTRUCTION);

        return TriageResult::fromArray($rawTriageData);
    }

    private function buildPrompt(Ticket $ticket): string
    {
        $payloadJson = json_encode($ticket->rawPayload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

        return <<<TEXT
Ticket ID: {$ticket->id}
Canal de Origen: {$ticket->sourceChannel->value}
Customer / Remitente ID: {$ticket->customerId}
Fecha de Recepción: {$ticket->createdAt->format('Y-m-d H:i:s')}

Payload recibido:
```json
{$payloadJson}
```

Analiza el caso, clasifica el ticket y genera el JSON estricto según las instrucciones de ingeniería.
TEXT;
    }
}

