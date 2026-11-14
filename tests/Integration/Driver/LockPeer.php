<?php

declare(strict_types=1);

namespace App\Tests\Integration\Driver;

use App\Core\Connection\Engine;

/**
 * Une seconde session, brute (PDO), pour tenir un verrou ou fermer un cycle d'interblocage contre la session testée.
 */
final class LockPeer
{
    public static function connect(string $env): \PDO
    {
        $p = TargetDatabase::parameters($env);
        $dsn = Engine::PostgreSql === TargetDatabase::engine($env)
            ? \sprintf('pgsql:host=%s;port=%d;dbname=%s', $p->host, $p->port, $p->database)
            : \sprintf('mysql:host=%s;port=%d;dbname=%s', $p->host, $p->port, $p->database);

        return new \PDO($dsn, $p->username, $p->password(), [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
    }
}
