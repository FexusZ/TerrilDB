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
    case Redis = 'redis';

    /**
     * Les instructions de ce moteur sont du SQL (Redis a ses propres commandes).
     */
    public function speaksSql(): bool
    {
        return self::Redis !== $this;
    }

    /**
     * Le pilote sait se connecter par un socket Unix, donc passer par un tunnel SSH (le tunnel n'offre qu'un socket).
     * ClickHouse (HTTP) et Redis (Predis) viendront avec le repli sur un port local.
     */
    public function reachableThroughJump(): bool
    {
        return match ($this) {
            self::MySql, self::MariaDb, self::PostgreSql => true,
            self::ClickHouse, self::Redis => false,
        };
    }

    public function defaultPort(): int
    {
        return match ($this) {
            self::MySql, self::MariaDb => 3306,
            self::PostgreSql => 5432,
            // Interface HTTP : TerrilDB ne parle pas le protocole natif.
            self::ClickHouse => 8123,
            self::Redis => 6379,
        };
    }
}
