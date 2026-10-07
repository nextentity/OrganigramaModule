#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Aplica el esquema de base de datos (idempotente).
 *
 * Uso:
 *   php database/migrate.php           Crea tablas, índices y triggers si no existen.
 *   php database/migrate.php --fresh   Elimina las tablas y las recrea desde cero.
 */

use App\Infrastructure\Database\DatabaseDriver;
use App\Infrastructure\Database\SchemaMigrator;

/** @var PDO $pdo */
$pdo = require dirname(__DIR__) . '/config/db.php';

$options = getopt('', ['fresh']);

try {
    $migrator = new SchemaMigrator($pdo, PROJECT_ROOT . '/database/schema');

    if (isset($options['fresh'])) {
        $migrator->dropAllTables();
        fwrite(STDOUT, "• Tablas eliminadas (--fresh)\n");
    }

    $executedStatements = $migrator->migrate();

    fwrite(STDOUT, sprintf(
        "✔ Esquema aplicado en %s (%d sentencias)\n",
        DatabaseDriver::fromPdo($pdo)->value,
        $executedStatements,
    ));
    exit(0);
} catch (Throwable $exception) {
    fwrite(STDERR, '✘ Error de migración: ' . $exception->getMessage() . PHP_EOL);
    exit(1);
}

