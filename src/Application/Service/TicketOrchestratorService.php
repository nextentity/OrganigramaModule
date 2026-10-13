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
            // Manejo defensivo: registra el error y transiciona el ticket a FAILED en SQLite
            $this->log(sprintf(
                '✘ [Worker Error] Fallo al procesar ticket %s: %s in %s:%d',
                $ticket->id,
                $exception->getMessage(),
                $exception->getFile(),
                $exception->getLine()
            ));

            try {
                $ticket->markAsFailed($exception->getMessage());
                $this->ticketRepository->update($ticket);
            } catch (Throwable $saveException) {
                $this->log(sprintf('✘ [Fatal DB Error] No se pudo guardar estado FAILED para %s: %s', $ticket->id, $saveException->getMessage()));
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

        $this->log(sprintf('✔ [GitHub] Issue #%d creado: %s', $issueNumber, $issueUrl));

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

        $this->log(sprintf('✔ [Jira] Card %s creada y vinculada con GitHub Issue #%d', $jiraKey, $issueNumber));

        // 3. Auto-Fix (Human-in-the-Loop) si aplica
        if ($triage->isAutoFixable) {
            $ticket->markAsAutoFixing($issueNumber, $jiraKey);
            $this->ticketRepository->update($ticket);

            $this->log(sprintf('🚀 [Auto-Fix] Disparando workflow_dispatch para Ticket %s...', $ticket->id));

            $this->gitHubClient->triggerWorkflowDispatch('auto-fix.yml', 'main', [
                'ticket_id'      => $ticket->id,
                'issue_number'   => (string) $issueNumber,
                'patch_hints'    => $triage->patchHints,
                'suggested_area' => $triage->suggestedArea,
            ]);
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
}

