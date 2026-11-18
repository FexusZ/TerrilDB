<?php

declare(strict_types=1);

namespace App\Core\Schema\Change\Algorithm;

use App\Core\Connection\Engine;
use App\Core\Driver\ServerFlavour;
use App\Core\Driver\ServerVersion;

/**
 * Le jeu de règles qui s'applique à un serveur : un moteur ET une série de versions dont les règles ont été vérifiées contre les
 * vrais moteurs (tests/Integration/Driver/AlterAlgorithmTest). Toute autre version, ou une version illisible, n'a pas de jeu :
 * aucune affirmation (les règles d'une série voisine peuvent différer, et un faux « instantané » rassurerait à tort).
 */
enum RuleFamily: string
{
    case MySql = 'mysql';
    case MariaDb = 'mariadb';
    case PostgreSql = 'postgresql';

    /** Les séries vérifiées : MySQL 8.4 et 26, MariaDB 11.8 et 13, PostgreSQL 18. */
    public static function for(Engine $engine, ?ServerVersion $version): ?self
    {
        if (null === $version) {
            return null;
        }

        return match ($engine) {
            Engine::MySql, Engine::MariaDb => self::mySqlFamily($version),
            Engine::PostgreSql => 18 === $version->major && ServerFlavour::Standard === $version->flavour ? self::PostgreSql : null,
            default => null,
        };
    }

    private static function mySqlFamily(ServerVersion $version): ?self
    {
        return match ($version->flavour) {
            ServerFlavour::Standard => (8 === $version->major && 4 === $version->minor) || 26 === $version->major ? self::MySql : null,
            ServerFlavour::MariaDb => (11 === $version->major && 8 === $version->minor) || 13 === $version->major ? self::MariaDb : null,
            ServerFlavour::Vitess => null,
        };
    }

    public function isMySqlFamily(): bool
    {
        return self::PostgreSql !== $this;
    }
}
