<?php

declare(strict_types=1);

namespace App\Domain\Exception;

final class NotFoundException extends DomainException
{
    public static function client(int $id): self
    {
        return new self("Cliente no encontrado con ID: {$id}", 404);
    }

    public static function user(int $id): self
    {
        return new self("Usuario no encontrado con ID: {$id}", 404);
    }
}

