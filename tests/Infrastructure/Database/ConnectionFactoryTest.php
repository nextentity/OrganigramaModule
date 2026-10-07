<?php

declare(strict_types=1);

namespace Tests\Infrastructure\Database;

use App\Infrastructure\Database\ConnectionFactory;
use App\Infrastructure\Database\DatabaseConfig;
use App\Infrastructure\Database\DatabaseConnectionException;
use App\Infrastructure\Database\DatabaseDriver;
use Tests\Support\TestCase;

final class ConnectionFactoryTest extends TestCase
{
    public function testSqliteConnectionEnforcesForeignKeys(): void
    {
        $pdo = (new ConnectionFactory(DatabaseConfig::forSqlite()))->create();

        $this->assertSame(DatabaseDriver::Sqlite, DatabaseDriver::fromPdo($pdo));
        $this->assertSame(1, (int) $pdo->query('PRAGMA foreign_keys')->fetchColumn());
    }

    public function testFallsBackToSqliteWhenMysqlIsUnreachable(): void
    {
        $logMessages = [];
        $factory = new ConnectionFactory(
            $this->unreachableMysqlConfig(fallbackToSqlite: true),
            static function (string $message) use (&$logMessages): void {
                $logMessages[] = $message;
            },
        );

        $pdo = $factory->create();

        $this->assertSame(DatabaseDriver::Sqlite, DatabaseDriver::fromPdo($pdo));
        $this->assertSame(1, count($logMessages), 'Debe registrar el motivo del fallback');
        $this->assertTrue(str_contains($logMessages[0], 'fallback SQLite'));
    }

    public function testThrowsWhenMysqlIsUnreachableAndFallbackIsDisabled(): void
    {
        $factory = new ConnectionFactory($this->unreachableMysqlConfig(fallbackToSqlite: false), static fn () => null);

        $this->assertThrows(static fn () => $factory->create(), DatabaseConnectionException::class, 'MySQL');
    }

    public function testRejectsUnsafeMysqlDatabaseIdentifier(): void
    {
        $config = new DatabaseConfig(
            driver: DatabaseDriver::Mysql,
            sqlitePath: DatabaseConfig::SQLITE_IN_MEMORY,
            fallbackToSqlite: false,
            mysqlDatabase: 'organigrama`; DROP DATABASE mysql; --',
        );

        $this->assertThrows(
            static fn () => (new ConnectionFactory($config))->create(),
            DatabaseConnectionException::class,
            'inválido',
        );
    }

    private function unreachableMysqlConfig(bool $fallbackToSqlite): DatabaseConfig
    {
        return new DatabaseConfig(
            driver: DatabaseDriver::Mysql,
            sqlitePath: DatabaseConfig::SQLITE_IN_MEMORY,
            fallbackToSqlite: $fallbackToSqlite,
            mysqlHost: '127.0.0.1',
            mysqlPort: 1, // Puerto sin servicio: conexión rechazada inmediatamente.
            connectTimeoutSeconds: 1,
        );
    }
}

