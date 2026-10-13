<?php

declare(strict_types=1);

namespace App\Infrastructure\Container;

final class NotFoundException extends ContainerException
{
    public function __construct(string $id)
    {
        parent::__construct("El servicio o identificador '{$id}' no fue encontrado en el contenedor.");
    }
}

