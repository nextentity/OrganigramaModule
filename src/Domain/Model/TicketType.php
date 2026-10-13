<?php

declare(strict_types=1);

namespace App\Domain\Model;

use ValueError;

/**
 * Tipo o categoría de solicitud identificada en el triaging.
 */
enum TicketType: string
{
    case BUG = 'bug';
    case OPERATIONAL_QUESTION = 'operational_question';
    case FEATURE_REQUEST = 'feature_request';

    public static function fromString(string $value): self
    {
        $normalized = strtolower(trim($value));
        $case = self::tryFrom($normalized);

        if ($case === null) {
            throw new ValueError("Tipo de ticket inválido: '{$value}'. Valores permitidos: bug, operational_question, feature_request.");
        }

        return $case;
    }
}

