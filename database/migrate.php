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

    // Sanitización retroactiva de tickets con API keys residuales en SQLite
    try {
        $checkStmt = $pdo->query("SELECT id, triage_result FROM support_tickets WHERE triage_result LIKE '%key=%' OR triage_result LIKE '%AIza%' OR triage_result LIKE '%AQ.%'");
        if ($checkStmt) {
            $updateStmt = $pdo->prepare('UPDATE support_tickets SET triage_result = :sanitized WHERE id = :id');
            while ($row = $checkStmt->fetch(PDO::FETCH_ASSOC)) {
                $sanitized = \App\Application\Service\TicketOrchestratorService::sanitizeMessage((string) $row['triage_result']);
                $updateStmt->execute([':sanitized' => $sanitized, ':id' => $row['id']]);
            }
        }
    } catch (Throwable) {
        // Ignorar de forma defensiva si no existe la tabla
    }

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

