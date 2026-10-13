<?php

declare(strict_types=1);

namespace App\Domain\Model;

use ValueError;

/**
 * Severidad del incidente/ticket clasificado por IA.
 */
enum TicketSeverity: string
{
    case LOW = 'low';
    case MEDIUM = 'medium';
    case HIGH = 'high';
    case CRITICAL = 'critical';

    public static function fromString(string $value): self
    {
        $normalized = strtolower(trim($value));
        $case = self::tryFrom($normalized);

        if ($case === null) {
            throw new ValueError("Severidad inválida: '{$value}'. Valores permitidos: low, medium, high, critical.");
        }

        return $case;
    }
}

