<?php

declare(strict_types=1);

namespace App\Infrastructure\Database;

use RuntimeException;
use Throwable;

final class DatabaseConnectionException extends RuntimeException
{
    public static function mysqlUnavailable(Throwable $previous): self
    {
        return new self(
            'No se pudo conectar a MySQL y el fallback a SQLite está deshabilitado: ' . $previous->getMessage(),
            0,
            $previous,
        );
    }

    public static function invalidIdentifier(string $identifier): self
    {
        return new self("Identificador de base de datos inválido: '{$identifier}'. Solo se permiten [A-Za-z0-9_].");
    }

    public static function sqliteDirectoryNotWritable(string $directory): self
    {
        return new self("No se pudo crear o escribir el directorio de SQLite: {$directory}");
    }
}

