<?php

declare(strict_types=1);

namespace App\Infrastructure\Http;

use App\Application\DTO\CreateTicketDTO;
use App\Application\Service\TicketIngestionService;
use App\Domain\Exception\TicketNotFoundException;
use App\Domain\Repository\TicketRepositoryInterface;
use App\Infrastructure\External\GitHub\GitHubClientInterface;
use Throwable;

/**
 * Controlador HTTP REST para la ingesta y consulta de tickets de soporte técnico.
 */
final class SupportTicketController
{
    private static int $lastReconciledAt = 0;

    public function __construct(
        private readonly TicketIngestionService $ingestionService,
        private readonly TicketRepositoryInterface $ticketRepository,
        private readonly ?GitHubClientInterface $gitHubClient = null,
    ) {
    }

    /**
     * Ingesta rápida: POST /api/v1/tickets
     * Responde 202 Accepted con payload {"ticket_id": "...", "status": "PENDING"}.
     */
    public function createTicket(): void
    {
        $body = Request::getJsonBody();
        $dto = CreateTicketDTO::fromArray($body);

        $result = $this->ingestionService->ingest($dto);

        JsonResponse::accepted($result);
    }

    /**
     * Listado de tickets recientes: GET /api/v1/tickets
     */
    public function listTickets(): void
    {
        $now = time();
        // Reconciliación automática y no bloqueante si pasaron al menos 3s desde la última comprobación
        if ($this->gitHubClient !== null && $this->gitHubClient->isConfigured() && ($now - self::$lastReconciledAt) >= 3) {
            self::$lastReconciledAt = $now;
            $this->reconcileActiveTickets();
        }

        $limit = isset($_GET['limit']) ? (int) $_GET['limit'] : 50;
        $tickets = $this->ticketRepository->findRecent($limit);

        JsonResponse::success(array_map(static fn ($ticket): array => $ticket->toArray(), $tickets));
    }

    /**
     * Reconciliación forzada e inmediata: POST /api/v1/tickets/sync
     */
    public function syncTickets(): void
    {
        self::$lastReconciledAt = time();
        $reconciled = $this->reconcileActiveTickets();

        JsonResponse::success([
            'reconciled' => $reconciled,
            'message'    => "Reconciliación completada: {$reconciled} ticket(s) sincronizados a RESOLVED.",
        ]);
    }

    /**
     * Reconcilia tickets en AUTO_FIXING u OPEN contra GitHub Issue status.
     */
    public function reconcileActiveTickets(): int
    {
        if ($this->gitHubClient === null || !$this->gitHubClient->isConfigured()) {
            return 0;
        }

        $activeTickets = $this->ticketRepository->findActiveWithExternalIssues(20);
        $count = 0;

        foreach ($activeTickets as $ticket) {
            $issueNumber = $ticket->githubIssueNumber();
            if ($issueNumber === null) {
                continue;
            }

            try {
                $issue = $this->gitHubClient->getIssue($issueNumber);
                if ($issue !== null && ($issue['state'] ?? '') === 'closed') {
                    $resolutionMessage = sprintf(
                        'Resuelto automáticamente tras fusión de Pull Request y cierre de GitHub Issue #%d.',
                        $issueNumber
                    );
                    $ticket->markAsResolved($resolutionMessage);
                    $this->ticketRepository->update($ticket);
                    $count++;
                }
            } catch (Throwable) {
                // Silencioso en contexto HTTP
            }
        }

        return $count;
    }

    /**
     * Consulta de estado: GET /api/v1/tickets/{ticketId}
     */
    public function getTicket(string $ticketId): void
    {
        $ticket = $this->ticketRepository->findById($ticketId);

        if ($ticket === null) {
            throw new TicketNotFoundException($ticketId);
        }

        JsonResponse::success($ticket->toArray());
    }

    /**
     * Reintento de ticket: POST /api/v1/tickets/{ticketId}/retry
     */
    public function retryTicket(string $ticketId): void
    {
        $ticket = $this->ticketRepository->findById($ticketId);

        if ($ticket === null) {
            throw new TicketNotFoundException($ticketId);
        }

        $ticket->retry();
        $this->ticketRepository->update($ticket);

        JsonResponse::accepted([
            'ticket_id' => $ticket->id,
            'status'    => 'PENDING',
            'message'   => 'Ticket reencolado para procesamiento por el worker.',
        ]);
    }
}

