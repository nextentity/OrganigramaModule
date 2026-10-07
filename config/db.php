<?php

declare(strict_types=1);

/**
 * Conexión PDO de la aplicación.
 *
 * Devuelve una instancia PDO lista para usar:
 *   - MySQL (DB_CONNECTION=mysql, por defecto). Crea la base si no existe.
 *   - Fallback automático a SQLite si MySQL no responde (DB_FALLBACK_TO_SQLITE=true).
 *   - SQLite directo con DB_CONNECTION=sqlite.
 *
 * Uso:  $pdo = require __DIR__ . '/../config/db.php';
 *
 * Variables de entorno (o archivo .env): ver .env.example
 */

require_once dirname(__DIR__) . '/bootstrap.php';

use App\Infrastructure\Database\ConnectionFactory;
use App\Infrastructure\Database\DatabaseConfig;

return (new ConnectionFactory(DatabaseConfig::fromEnvironment(PROJECT_ROOT)))->create();

