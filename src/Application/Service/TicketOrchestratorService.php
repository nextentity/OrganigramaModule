<?php

declare(strict_types=1);

namespace App\Application\Service;

use App\Domain\Model\Ticket;
use App\Domain\Model\TicketType;
use App\Domain\Repository\TicketRepositoryInterface;
use App\Infrastructure\External\GitHub\GitHubClientInterface;
use App\Infrastructure\External\Jira\JiraClientInterface;
use Closure;
use Throwable;

/**
 * Orquestador principal del ciclo de triaging y resolución de tickets.
 * Coordina Triage LLM -> Notificación GitHub/Jira -> Auto-PR -> Actualización SQLite.
 */
final class TicketOrchestratorService
{
    /** @var Closure(string): void */
    private readonly Closure $logger;

    /**
     * @param (Closure(string): void)|null $logger
     */
    public function __construct(
        private readonly TicketRepositoryInterface $ticketRepository,
        private readonly LlmTriageServiceInterface $triageService,
        private readonly GitHubClientInterface $gitHubClient,
        private readonly JiraClientInterface $jiraClient,
        ?Closure $logger = null,
    ) {
        $this->logger = $logger ?? static fn (string $msg): bool => error_log($msg);
    }

    /**
     * Procesa un lote de tickets pendientes.
     *
     * @return int Cantidad de tickets procesados exitosamente en este ciclo.
     */
    public function processPendingBatch(int $limit = 10): int
    {
        $pendingTickets = $this->ticketRepository->findPendingForProcessing($limit);
        $processedCount = 0;

        foreach ($pendingTickets as $ticket) {
            // Reclamo atómico para evitar condiciones de carrera entre workers concurrentes
            if (!$this->ticketRepository->claimTicket($ticket->id)) {
                $this->log(sprintf('[Worker] Ticket %s ya reclamado por otro proceso.', $ticket->id));
                continue;
            }

            // Recarga el ticket en su estado reclamado
            $freshTicket = $this->ticketRepository->findById($ticket->id);
            if ($freshTicket === null) {
                continue;
            }

            $this->processSingleTicket($freshTicket);
            $processedCount++;
        }

        return $processedCount;
    }

    /**
     * Procesa un ticket individual con manejo defensivo de fallos.
     */
    public function processSingleTicket(Ticket $ticket): void
    {
        $this->log(sprintf('▶ [Worker] Iniciando triage para Ticket %s (%s)...', $ticket->id, $ticket->sourceChannel->value));

        try {
            // 1. Ejecutar Triage con LLM (JSON Schema estricto)
            $triageResult = $this->triageService->triage($ticket);
            $ticket->markAsTriaged($triageResult);
            $this->ticketRepository->update($ticket);

            $this->log(sprintf(
                '✔ [Triage] Ticket %s clasificado como %s [%s] (AutoFix: %s)',
                $ticket->id,
                $triageResult->type->value,
                $triageResult->severity->value,
                $triageResult->isAutoFixable ? 'SÍ' : 'NO'
            ));

            // 2. Bifurcación según el tipo de solicitud
            switch ($triageResult->type) {
                case TicketType::OPERATIONAL_QUESTION:
                    // Duda operativa: respuesta sugerida directa, sin molestar a ingeniería
                    $ticket->markAsResolved($triageResult->cannedResponse);
                    $this->ticketRepository->update($ticket);
                    $this->log(sprintf('✔ [Resolved] Ticket %s resuelto con respuesta enlatada.', $ticket->id));
                    break;

                case TicketType::BUG:
                    $this->handleBugFlow($ticket);
                    break;

                case TicketType::FEATURE_REQUEST:
                    $this->handleFeatureRequestFlow($ticket);
                    break;
            }
        } catch (Throwable $exception) {
            $safeMessage = self::sanitizeMessage($exception->getMessage());

            if (str_contains($safeMessage, '503') || str_contains($safeMessage, 'UNAVAILABLE') || str_contains($safeMessage, 'high demand')) {
                $this->log(sprintf('⚠ [High Demand] Gemini reporta alta demanda de concurrencia temporal (503) para ticket %s.', $ticket->id));
            }

            // Manejo defensivo: registra el error sanitizado y transiciona el ticket a FAILED en SQLite
            $this->log(sprintf(
                '✘ [Worker Error] Fallo al procesar ticket %s: %s in %s:%d',
                $ticket->id,
                $safeMessage,
                $exception->getFile(),
                $exception->getLine()
            ));

            try {
                $ticket->markAsFailed($safeMessage);
                $this->ticketRepository->update($ticket);
            } catch (Throwable $saveException) {
                $this->log(sprintf('✘ [Fatal DB Error] No se pudo guardar estado FAILED para %s: %s', $ticket->id, self::sanitizeMessage($saveException->getMessage())));
            }
        }
    }

    private function handleBugFlow(Ticket $ticket): void
    {
        $triage = $ticket->triageResult();
        if ($triage === null) {
            return;
        }

        // 1. Crear GitHub Issue formateado con Markdown y labels
        $title = sprintf('[Bug %s] %s', strtoupper($triage->severity->value), $triage->summary);
        $body = $this->gitHubClient->formatIssueBody($ticket, $triage);
        $labels = ['bug', 'triage:ai', 'severity:' . $triage->severity->value];

        if ($triage->isAutoFixable) {
            $labels[] = 'auto-fixable';
        }

        $githubIssue = $this->gitHubClient->createIssue($title, $body, $labels);
        $issueNumber = $githubIssue['number'];
        $issueUrl = $githubIssue['html_url'];

        if (!empty($githubIssue['is_mock'])) {
            $this->log(sprintf('⚠ [GitHub (MOCK)] GITHUB_TOKEN no configurado en Railway. Issue #%d simulado (no creado en repo real): %s', $issueNumber, $issueUrl));
        } else {
            $this->log(sprintf('✔ [GitHub] Issue #%d creado en GitHub: %s', $issueNumber, $issueUrl));
        }

        // 2. Crear Card vinculada en Jira Cloud REST API v3
        $jiraSummary = sprintf('[Bug #%d] %s', $issueNumber, $triage->summary);
        $jiraDescription = sprintf(
            "Severidad: %s\nÁrea: %s\n\nResumen:\n%s\n\nGitHub Issue:\n%s\n\nAuto-Fixable: %s",
            $triage->severity->value,
            $triage->suggestedArea,
            $triage->summary,
            $issueUrl,
            $triage->isAutoFixable ? 'Sí' : 'No'
        );

        $jiraCard = $this->jiraClient->createIssue($jiraSummary, $jiraDescription, 'Bug', $issueUrl);
        $jiraKey = $jiraCard['key'];

        if (!empty($jiraCard['is_mock'])) {
            $this->log(sprintf('⚠ [Jira (MOCK)] JIRA_API_TOKEN no configurado en Railway. Card %s simulada.', $jiraKey));
        } else {
            $this->log(sprintf('✔ [Jira] Card %s creada y vinculada con GitHub Issue #%d', $jiraKey, $issueNumber));
        }

        // 3. Auto-Fix (Human-in-the-Loop) si aplica
        if ($triage->isAutoFixable) {
            $ticket->markAsAutoFixing($issueNumber, $jiraKey);
            $this->ticketRepository->update($ticket);

            if (!empty($githubIssue['is_mock'])) {
                $this->log(sprintf('⚠ [Auto-Fix (MOCK)] GITHUB_TOKEN no configurado en Railway. Se omite el trigger de workflow_dispatch a GitHub Actions.'));
            } else {
                $this->log(sprintf('🚀 [Auto-Fix] Disparando workflow_dispatch para Ticket %s...', $ticket->id));

                $this->gitHubClient->triggerWorkflowDispatch('auto-fix.yml', 'main', [
                    'ticket_id'      => $ticket->id,
                    'issue_number'   => (string) $issueNumber,
                    'patch_hints'    => $triage->patchHints,
                    'suggested_area' => $triage->suggestedArea,
                ]);
            }
        } else {
            $ticket->markAsOpen($issueNumber, $jiraKey);
            $this->ticketRepository->update($ticket);
        }
    }

    private function handleFeatureRequestFlow(Ticket $ticket): void
    {
        $triage = $ticket->triageResult();
        if ($triage === null) {
            return;
        }

        $title = sprintf('[Feature Request] %s', $triage->summary);
        $body = $this->gitHubClient->formatIssueBody($ticket, $triage);
        $labels = ['enhancement', 'triage:ai'];

        $githubIssue = $this->gitHubClient->createIssue($title, $body, $labels);
        $issueNumber = $githubIssue['number'];
        $issueUrl = $githubIssue['html_url'];

        $jiraCard = $this->jiraClient->createIssue(
            summary: $title,
            description: "Feature request capturado desde soporte.\n\nGitHub: {$issueUrl}",
            issueType: 'New Feature',
            githubIssueUrl: $issueUrl,
        );

        $ticket->markAsOpen($issueNumber, $jiraCard['key']);
        $this->ticketRepository->update($ticket);

        $this->log(sprintf('✔ [Feature Request] Ticket %s registrado en GitHub #%d y Jira %s', $ticket->id, $issueNumber, $jiraCard['key']));
    }

    private function log(string $message): void
    {
        ($this->logger)($message);
    }

    /**
     * Sanitiza y ofusca tokens, API keys y contraseñas de cualquier mensaje de error o log.
     */
    public static function sanitizeMessage(string $message): string
    {
        // 1. Redactar Google API Keys (AIza... o AQ...)
        $message = (string) preg_replace('/(?:AIza|AQ\.)[0-9A-Za-z_-]{20,}/', '***API_KEY_REDACTED***', $message);

        // 2. Redactar GitHub Tokens (ghp_..., github_pat_...)
        $message = (string) preg_replace('/ghp_[0-9A-Za-z]{30,}/', 'ghp_***REDACTED***', $message);
        $message = (string) preg_replace('/github_pat_[0-9A-Za-z_]{60,}/', 'github_pat_***REDACTED***', $message);

        // 3. Redactar query params con credenciales (?key=..., &token=...)
        $message = (string) preg_replace('/([?&](?:key|token|api_key|secret|password|access_token)=)[^&\s"\']+/i', '$1***REDACTED***', $message);

        // 4. Redactar cabeceras Bearer / Basic
        $message = (string) preg_replace('/(Bearer\s+)[A-Za-z0-9._~+\/-]{10,}/i', '$1***REDACTED***', $message);
        $message = (string) preg_replace('/(Basic\s+)[A-Za-z0-9+\/=]{10,}/i', '$1***REDACTED***', $message);

        return $message;
    }
}

