#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Seed de datos de prueba: dos empresas con jerarquías de 3 niveles.
 *
 * Aplica primero el esquema (idempotente) y luego inserta los tenants que aún no existan.
 *
 * Uso:
 *   php database/seed.php           Siembra solo los clientes faltantes (re-ejecutable).
 *   php database/seed.php --fresh   Recrea el esquema completo y vuelve a sembrar.
 */

use App\Infrastructure\Config\EnvironmentLoader;
use App\Infrastructure\Database\DatabaseDriver;
use App\Infrastructure\Database\SchemaMigrator;
use App\Infrastructure\Database\Seeding\OrganizationFixture;
use App\Infrastructure\Database\Seeding\OrganizationSeeder;

/** @var PDO $pdo */
$pdo = require dirname(__DIR__) . '/config/db.php';

$options = getopt('', ['fresh']);

try {
    $migrator = new SchemaMigrator($pdo, PROJECT_ROOT . '/database/schema');

    if (isset($options['fresh'])) {
        $migrator->dropAllTables();
    }

    $migrator->migrate();

    $organizations = OrganizationFixture::collectionFromArray(
        require PROJECT_ROOT . '/database/fixtures/demo_organizations.php'
    );

    $seeder = new OrganizationSeeder(
        pdo: $pdo,
        defaultPlainPassword: EnvironmentLoader::get('SEED_DEFAULT_PASSWORD', 'Demo1234!'),
    );
    $report = $seeder->seed($organizations);

    fwrite(STDOUT, sprintf("Driver: %s\n", DatabaseDriver::fromPdo($pdo)->value));

    foreach ($report->createdClients as $clientName) {
        fwrite(STDOUT, "  ✔ Cliente creado: {$clientName}\n");
    }

    foreach ($report->skippedClients as $clientName) {
        fwrite(STDOUT, "  • Cliente existente (omitido): {$clientName}\n");
    }

    fwrite(STDOUT, sprintf("✔ Seed finalizado: %d usuarios insertados.\n\n", $report->usersCreated));

    printHierarchy($pdo);
    exit(0);
} catch (Throwable $exception) {
    fwrite(STDERR, '✘ Error en el seed: ' . $exception->getMessage() . PHP_EOL);
    exit(1);
}

/**
 * Imprime el organigrama de cada cliente (lectura estrictamente filtrada por client_id).
 */
function printHierarchy(PDO $pdo): void
{
    $clients = $pdo->prepare('SELECT id, name FROM clients ORDER BY id');
    $clients->execute();

    $usersByClient = $pdo->prepare(
        'SELECT id, user_id, name, last_name FROM users WHERE client_id = :client_id ORDER BY id'
    );

    foreach ($clients->fetchAll() as $client) {
        fwrite(STDOUT, "🏢 {$client['name']}\n");

        $usersByClient->bindValue(':client_id', (int) $client['id'], PDO::PARAM_INT);
        $usersByClient->execute();

        /** @var array<int, list<array{id: int, name: string, last_name: string}>> $childrenBySupervisor */
        $childrenBySupervisor = [];
        foreach ($usersByClient->fetchAll() as $user) {
            $childrenBySupervisor[(int) ($user['user_id'] ?? 0)][] = $user;
        }

        printBranch($childrenBySupervisor, supervisorId: 0, level: 1);
        fwrite(STDOUT, PHP_EOL);
    }
}

/**
 * @param array<int, list<array{id: int, name: string, last_name: string}>> $childrenBySupervisor
 */
function printBranch(array $childrenBySupervisor, int $supervisorId, int $level): void
{
    foreach ($childrenBySupervisor[$supervisorId] ?? [] as $user) {
        fwrite(STDOUT, sprintf("%s└─ [N%d] %s %s\n", str_repeat('    ', $level), $level, $user['name'], $user['last_name']));
        printBranch($childrenBySupervisor, (int) $user['id'], $level + 1);
    }
}
