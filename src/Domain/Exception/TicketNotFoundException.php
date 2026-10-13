<?php

declare(strict_types=1);

namespace App\Domain\Exception;

final class TicketNotFoundException extends DomainException
{
    public function __construct(string $ticketId)
    {
        parent::__construct("Ticket no encontrado: '{$ticketId}'", 404, ['ticket_id' => $ticketId]);
    }
}

