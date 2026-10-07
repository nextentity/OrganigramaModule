<?php

declare(strict_types=1);

namespace App\Infrastructure\Database;

use Closure;
use PDO;
use PDOException;

/**
 * Crea conexiones PDO endurecidas.
 *
 * Estrategia: intenta MySQL; si no está disponible (servidor caído, credenciales, etc.)
 * y el fallback está habilitado, conecta automáticamente a SQLite.
 */
final class ConnectionFactory
{
    private const MYSQL_UNKNOWN_DATABASE_ERROR = 1049;

    /** @var array<int, mixed> */
    private const COMMON_PDO_OPTIONS = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_STRINGIFY_FETCHES  => false,
    ];

    /** @var Closure(string): void */
    private readonly Closure $logger;

    /**
     * @param (Closure(string): void)|null $logger
     */
    public function __construct(
        private readonly DatabaseConfig $config,
        ?Closure $logger = null,
    ) {
        $this->logger = $logger ?? static fn (string $message): bool => error_log($message);
    }

    public function create(): PDO
    {
        if ($this->config->driver === DatabaseDriver::Sqlite) {
            return $this->connectSqlite();
        }

        try {
            return $this->connectMysql();
        } catch (PDOException $mysqlException) {
            if (!$this->config->fallbackToSqlite) {
                throw DatabaseConnectionException::mysqlUnavailable($mysqlException);
            }

            ($this->logger)(sprintf(
                '[db] MySQL no disponible en %s:%d (%s). Usando fallback SQLite: %s',
                $this->config->mysqlHost,
                $this->config->mysqlPort,
                $mysqlException->getMessage(),
                $this->config->sqlitePath,
            ));

            return $this->connectSqlite();
        }
    }

    private function connectMysql(): PDO
    {
        $databaseName = $this->config->mysqlDatabase;
        $this->assertSafeIdentifier($databaseName);

        try {
            $pdo = $this->openMysql($databaseName);
        } catch (PDOException $exception) {
            if (!$this->isUnknownDatabaseError($exception)) {
                throw $exception;
            }

            // Los identificadores no se pueden parametrizar con PDO: se validan con lista blanca (assertSafeIdentifier).
            $this->openMysql(null)->exec(sprintf(
                'CREATE DATABASE IF NOT EXISTS `%s` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci',
                $databaseName,
            ));
            $pdo = $this->openMysql($databaseName);
        }

        $pdo->exec("SET SESSION sql_mode = 'STRICT_ALL_TABLES,NO_ZERO_DATE,NO_ZERO_IN_DATE,ERROR_FOR_DIVISION_BY_ZERO'");

        return $pdo;
    }

    private function openMysql(?string $databaseName): PDO
    {
        $dsn = sprintf('mysql:host=%s;port=%d;charset=utf8mb4', $this->config->mysqlHost, $this->config->mysqlPort);

        if ($databaseName !== null) {
            $dsn .= ';dbname=' . $databaseName;
        }

        return new PDO($dsn, $this->config->mysqlUser, $this->config->mysqlPassword, self::COMMON_PDO_OPTIONS + [
            PDO::ATTR_EMULATE_PREPARES => false, // Prepared statements reales en el servidor.
            PDO::ATTR_TIMEOUT          => $this->config->connectTimeoutSeconds,
        ]);
    }

    private function connectSqlite(): PDO
    {
        $path = $this->config->sqlitePath;

        if (!$this->config->isSqliteInMemory()) {
            $this->ensureDirectoryExists(dirname($path));
        }

        $pdo = new PDO('sqlite:' . $path, null, null, self::COMMON_PDO_OPTIONS);

        // SQLite no aplica claves foráneas por defecto: imprescindible para la integridad jerárquica.
        $pdo->exec('PRAGMA foreign_keys = ON');
        $pdo->exec('PRAGMA busy_timeout = 5000');

        if (!$this->config->isSqliteInMemory()) {
            $pdo->exec('PRAGMA journal_mode = WAL');
        }

        return $pdo;
    }

    private function ensureDirectoryExists(string $directory): void
    {
        if (is_dir($directory)) {
            return;
        }

        if (!@mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw DatabaseConnectionException::sqliteDirectoryNotWritable($directory);
        }
    }

    private function assertSafeIdentifier(string $identifier): void
    {
        if (preg_match('/^[A-Za-z0-9_]{1,64}$/', $identifier) !== 1) {
            throw DatabaseConnectionException::invalidIdentifier($identifier);
        }
    }

    private function isUnknownDatabaseError(PDOException $exception): bool
    {
        $driverErrorCode = (int) ($exception->errorInfo[1] ?? 0);

        return $driverErrorCode === self::MYSQL_UNKNOWN_DATABASE_ERROR
            || str_contains($exception->getMessage(), '[' . self::MYSQL_UNKNOWN_DATABASE_ERROR . ']');
    }
}

