<?php

declare(strict_types=1);

// Sous-processus de BlockedStatement : ouvre une session au délai de verrou court, dit « ready <identifiant> », lance l'instruction
// qui attend le verrou, puis rend « ok » ou « failed <SQLSTATE> <code moteur> ». Jamais lancé hors des tests.

use App\Core\Connection\Engine;
use App\Tests\Integration\Driver\LockPeer;
use App\Tests\Integration\Driver\TargetDatabase;
use App\Tests\Integration\Support\BlockingSession;

require dirname(__DIR__, 3).'/vendor/autoload.php';

/** @var list<string> $args */
$args = array_values(array_map(static fn (mixed $a): string => is_string($a) ? $a : '', is_array($_SERVER['argv'] ?? null) ? $_SERVER['argv'] : []));
$env = $args[1] ?? '';
$seconds = max(1, (int) ($args[2] ?? '1'));
$sql = $args[3] ?? '';

$pdo = LockPeer::connect($env);
if (Engine::PostgreSql === TargetDatabase::engine($env)) {
    $pdo->exec(sprintf("SET lock_timeout = '%dms'", $seconds * 1000));
    $id = BlockingSession::scalar($pdo, 'SELECT pg_backend_pid()');
} else {
    $pdo->exec(sprintf('SET SESSION innodb_lock_wait_timeout = %d', $seconds));
    $pdo->exec(sprintf('SET SESSION lock_wait_timeout = %d', $seconds));
    $id = BlockingSession::scalar($pdo, 'SELECT CONNECTION_ID()');
}
$pdo->exec('BEGIN');
echo 'ready '.($id ?: '0')."\n";
fflush(\STDOUT);

try {
    $pdo->exec($sql);
    $pdo->exec('COMMIT');
    echo "ok\n";
} catch (PDOException $e) {
    $info = is_array($e->errorInfo) ? $e->errorInfo : [];
    $state = is_string($info[0] ?? null) ? $info[0] : '';
    $code = is_scalar($info[1] ?? null) ? (string) $info[1] : '';
    echo 'failed '.$state.' '.$code."\n";
    try {
        $pdo->exec('ROLLBACK');
    } catch (PDOException) {
    }
}
