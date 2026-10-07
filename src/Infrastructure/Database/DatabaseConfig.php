<?php

declare(strict_types=1);

namespace App\Infrastructure\Database;

use App\Infrastructure\Config\EnvironmentLoader;
use InvalidArgumentException;

/**
 * Configuración inmutable de la conexión a base de datos.
 */
final readonly class DatabaseConfig
{
    public const SQLITE_IN_MEMORY = ':memory:';

    public function __construct(
        public DatabaseDriver $driver,
        public string $sqlitePath,
        public bool $fallbackToSqlite = true,
        public string $mysqlHost = '127.0.0.1',
        public int $mysqlPort = 3306,
        public string $mysqlDatabase = 'organigrama',
        public string $mysqlUser = 'root',
        public string $mysqlPassword = '',
        public int $connectTimeoutSeconds = 2,
    ) {
        if ($this->mysqlPort < 1 || $this->mysqlPort > 65535) {
            throw new InvalidArgumentException("Puerto MySQL inválido: {$this->mysqlPort}");
        }

        if ($this->sqlitePath === '') {
            throw new InvalidArgumentException('La ruta de SQLite no puede estar vacía.');
        }
    }

    public static function fromEnvironment(string $projectRoot): self
    {
        $driverName = strtolower(EnvironmentLoader::get('DB_CONNECTION', DatabaseDriver::Mysql->value));
        $driver = DatabaseDriver::tryFrom($driverName)
            ?? throw new InvalidArgumentException("DB_CONNECTION no soportado: {$driverName}");

        return new self(
            driver: $driver,
            sqlitePath: self::resolveSqlitePath(
                EnvironmentLoader::get('DB_SQLITE_PATH', 'storage/database/organigrama.sqlite'),
                $projectRoot,
            ),
            fallbackToSqlite: EnvironmentLoader::getBool('DB_FALLBACK_TO_SQLITE', true),
            mysqlHost: EnvironmentLoader::get('DB_HOST', '127.0.0.1'),
            mysqlPort: EnvironmentLoader::getInt('DB_PORT', 3306),
            mysqlDatabase: EnvironmentLoader::get('DB_DATABASE', 'organigrama'),
            mysqlUser: EnvironmentLoader::get('DB_USERNAME', 'root'),
            mysqlPassword: EnvironmentLoader::get('DB_PASSWORD', '') ?? '',
            connectTimeoutSeconds: EnvironmentLoader::getInt('DB_CONNECT_TIMEOUT', 2),
        );
    }

    public static function forSqlite(string $sqlitePath = self::SQLITE_IN_MEMORY): self
    {
        return new self(driver: DatabaseDriver::Sqlite, sqlitePath: $sqlitePath, fallbackToSqlite: false);
    }

    public function isSqliteInMemory(): bool
    {
        return $this->sqlitePath === self::SQLITE_IN_MEMORY;
    }

    private static function resolveSqlitePath(string $path, string $projectRoot): string
    {
        if ($path === self::SQLITE_IN_MEMORY || str_starts_with($path, '/')) {
            return $path;
        }

        return rtrim($projectRoot, '/') . '/' . preg_replace('#^\./#', '', $path);
    }
}
