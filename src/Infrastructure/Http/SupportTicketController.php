<?php

declare(strict_types=1);

namespace App\Infrastructure\Http;

use App\Application\DTO\CreateTicketDTO;
use App\Application\Service\TicketIngestionService;
use App\Domain\Exception\TicketNotFoundException;
use App\Domain\Repository\TicketRepositoryInterface;

/**
 * Controlador HTTP REST para la ingesta y consulta de tickets de soporte técnico.
 */
final class SupportTicketController
{
    public function __construct(
        private readonly TicketIngestionService $ingestionService,
        private readonly TicketRepositoryInterface $ticketRepository,
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
        $limit = isset($_GET['limit']) ? (int) $_GET['limit'] : 50;
        $tickets = $this->ticketRepository->findRecent($limit);

        JsonResponse::success(array_map(static fn ($ticket): array => $ticket->toArray(), $tickets));
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

