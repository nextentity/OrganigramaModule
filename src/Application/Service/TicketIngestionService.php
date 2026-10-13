<?php

declare(strict_types=1);

namespace App\Application\Service;

use App\Application\DTO\CreateTicketDTO;
use App\Domain\Model\Ticket;
use App\Domain\Repository\TicketRepositoryInterface;
use App\Infrastructure\Support\Uuid;

/**
 * Servicio de aplicación para la ingesta rápida de tickets de soporte.
 * Persiste con estado PENDING y retorna el identificador para respuesta 202 inmediata.
 */
final class TicketIngestionService
{
    public function __construct(
        private readonly TicketRepositoryInterface $ticketRepository,
    ) {
    }

    /**
     * @return array{ticket_id: string, status: string}
     */
    public function ingest(CreateTicketDTO $dto): array
    {
        $ticketId = $dto->ticketId ?? Uuid::v4();

        $ticket = Ticket::createPending(
            id: $ticketId,
            sourceChannel: $dto->sourceChannel,
            customerId: $dto->customerId,
            rawPayload: $dto->rawPayload,
        );

        $this->ticketRepository->save($ticket);

        return [
            'ticket_id' => $ticket->id,
            'status'    => $ticket->status()->value,
        ];
    }
}

