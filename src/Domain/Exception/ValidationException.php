<?php

declare(strict_types=1);

namespace App\Domain\Exception;

final class ValidationException extends DomainException
{
    /**
     * @param array<string, string> $errors
     */
    public static function withErrors(array $errors): self
    {
        return new self('Error de validación en la solicitud', 422, $errors);
    }

    public static function single(string $field, string $message): self
    {
        return new self($message, 422, [$field => $message]);
    }
}

