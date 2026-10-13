<?php

declare(strict_types=1);

namespace App\Domain\Model;

use ValueError;

/**
 * Canal de origen del ticket de soporte.
 */
enum SourceChannel: string
{
    case WHATSAPP = 'whatsapp';
    case WEBFORM = 'webform';
    case HELPDESK = 'helpdesk';
    case API = 'api';
    case EMAIL = 'email';

    public static function fromString(string $value): self
    {
        $normalized = strtolower(trim($value));
        $case = self::tryFrom($normalized);

        if ($case === null) {
            throw new ValueError("Canal de origen inválido: '{$value}'. Valores permitidos: whatsapp, webform, helpdesk, api, email.");
        }

        return $case;
    }
}

