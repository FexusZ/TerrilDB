<?php

declare(strict_types=1);

// Sous-processus des tests de verrous : ouvre sa transaction, dit son identifiant de session, puis lance l'instruction qui
// attend le verrou (au plus 20 s côté serveur). Jamais lancé hors des tests.

use App\Core\Connection\Engine;
use App\Driver\DbalConnector;
use App\Tests\Integration\Driver\TargetDatabase;

require dirname(__DIR__, 3).'/vendor/autoload.php';

/** @var list<string> $arguments */
$arguments = is_array($_SERVER['argv'] ?? null) ? array_values(array_map(static fn (mixed $a): string => is_string($a) ? $a : '', $_SERVER['argv'])) : [];
$envVar = $arguments[1] ?? '';
$sql = $arguments[2] ?? '';
$engine = TargetDatabase::engine($envVar);
$connection = (new DbalConnector())->connect($engine, TargetDatabase::parameters($envVar));
if (Engine::PostgreSql === $engine) {
    $connection->executeStatement("SET lock_timeout = '20s'");
    $connection->executeStatement('BEGIN');
    $id = $connection->fetchOne('SELECT pg_backend_pid()');
} else {
    $connection->executeStatement('SET SESSION innodb_lock_wait_timeout = 20');
    $connection->executeStatement('SET SESSION lock_wait_timeout = 20');
    $connection->executeStatement('START TRANSACTION');
    $id = $connection->fetchOne('SELECT CONNECTION_ID()');
}
echo is_scalar($id) ? (string) $id : '', "\n";
fflush(\STDOUT);

try {
    $connection->executeStatement($sql);
    $connection->executeStatement('COMMIT');
} catch (Throwable) {
    exit(1);
}
