<?php

declare(strict_types=1);

namespace App\Core\Connection;

/**
 * Moteurs de base de données pris en charge. La valeur est ce qui est stocké en base interne : elle ne change pas.
 */
enum Engine: string
{
    case MySql = 'mysql';
    case MariaDb = 'mariadb';
    case PostgreSql = 'postgresql';
    case ClickHouse = 'clickhouse';

    public function defaultPort(): int
    {
        return match ($this) {
            self::MySql, self::MariaDb => 3306,
            self::PostgreSql => 5432,
            // Interface HTTP : TerrilDB ne parle pas le protocole natif.
            self::ClickHouse => 8123,
        };
    }
}
