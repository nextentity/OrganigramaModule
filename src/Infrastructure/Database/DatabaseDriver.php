<?php

declare(strict_types=1);

namespace App\Infrastructure\Database;

use PDO;

enum DatabaseDriver: string
{
    case Mysql = 'mysql';
    case Sqlite = 'sqlite';

    public static function fromPdo(PDO $pdo): self
    {
        return self::from((string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME));
    }
}

