<?php

declare(strict_types=1);

namespace App\Infrastructure\Database;

use PDO;
use RuntimeException;

/**
 * Aplica el esquema correspondiente al driver activo (idempotente).
 */
final class SchemaMigrator
{
    /** Orden seguro de borrado respetando claves foráneas. */
    private const TABLES_IN_DROP_ORDER = ['support_tickets', 'users', 'clients'];

    public function __construct(
        private readonly PDO $pdo,
        private readonly string $schemaDirectory,
        private readonly SqlScriptParser $parser = new SqlScriptParser(),
        private readonly ?string $migrationsDirectory = null,
    ) {
    }

    /**
     * @return int Cantidad de sentencias ejecutadas.
     */
    public function migrate(): int
    {
        $statements = $this->parser->parse($this->readSchemaFor(DatabaseDriver::fromPdo($this->pdo)));

        $migrationsDir = $this->migrationsDirectory ?? (defined('PROJECT_ROOT') ? PROJECT_ROOT . '/migrations' : null);
        if ($migrationsDir !== null && is_dir($migrationsDir)) {
            $migrationFiles = glob(rtrim($migrationsDir, '/') . '/*.sql') ?: [];
            sort($migrationFiles);
            foreach ($migrationFiles as $migrationFile) {
                $sql = file_get_contents($migrationFile);
                if ($sql !== false) {
                    $statements = array_merge($statements, $this->parser->parse($sql));
                }
            }
        }

        foreach ($statements as $statement) {
            $this->pdo->exec($statement);
        }

        return count($statements);
    }

    public function dropAllTables(): void
    {
        foreach (self::TABLES_IN_DROP_ORDER as $tableName) {
            // Nombres de tabla constantes y controlados (no provienen de input externo).
            $this->pdo->exec("DROP TABLE IF EXISTS {$tableName}");
        }
    }

    private function readSchemaFor(DatabaseDriver $driver): string
    {
        $schemaFile = rtrim($this->schemaDirectory, '/') . "/{$driver->value}.sql";
        $schema = is_readable($schemaFile) ? file_get_contents($schemaFile) : false;

        if ($schema === false) {
            throw new RuntimeException("No se pudo leer el esquema: {$schemaFile}");
        }

        return $schema;
    }
}

