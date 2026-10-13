<?php

declare(strict_types=1);

namespace App\Domain\Exception;

final class InvalidTicketTransitionException extends DomainException
{
    public function __construct(string $ticketId, string $currentStatus, string $targetStatus, string $reason = '')
    {
        $message = sprintf(
            "Transición de estado inválida para el ticket '%s': no se puede pasar de '%s' a '%s'.%s",
            $ticketId,
            $currentStatus,
            $targetStatus,
            $reason !== '' ? " Motivo: {$reason}" : '',
        );

        parent::__construct($message, 409, [
            'ticket_id'      => $ticketId,
            'current_status' => $currentStatus,
            'target_status'  => $targetStatus,
        ]);
    }
}

