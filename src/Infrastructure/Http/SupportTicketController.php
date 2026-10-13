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
}

