<?php

declare(strict_types=1);

namespace App\Core\Guard;

use App\Core\Connection\Engine;

/**
 * Les règles lexicales qui comptent pour le filtre : MySQL et MariaDB lisent le SQL de la même façon,
 * PostgreSQL autrement (commentaires, échappements, guillemets).
 */
enum SqlDialect
{
    case MySql;
    case PostgreSql;

    public static function forEngine(Engine $engine): self
    {
        return match ($engine) {
            Engine::MySql, Engine::MariaDb => self::MySql,
            Engine::PostgreSql => self::PostgreSql,
        };
    }
}
