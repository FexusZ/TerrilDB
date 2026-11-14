<?php

declare(strict_types=1);

/*
 * Pair d'un test d'interblocage (lancé en sous-processus : PDO est synchrone). Verrouille les lignes 2, 3 et 4 (plus de travail que
 * la session testée, donc jamais choisi comme victime par MySQL), annonce « ready », attend un peu puis réclame la ligne 1, que la
 * session testée tient déjà : le cycle se ferme. Sur PostgreSQL le délai de détection est allongé ici pour que la victime soit
 * toujours la session testée. Usage : php lock_peer.php <variable d'environnement> <table entre guillemets>
 */

use App\Tests\Integration\Driver\LockPeer;

require dirname(__DIR__, 3).'/vendor/autoload.php';

/** @var list<string> $args */
$args = $_SERVER['argv'];
[, $env, $table] = $args;
$pdo = LockPeer::connect($env);
if ('pgsql' === $pdo->getAttribute(PDO::ATTR_DRIVER_NAME)) {
    $pdo->exec("SET deadlock_timeout = '10s'");
}
$pdo->exec('BEGIN');
$pdo->exec(sprintf('UPDATE %s SET v = v + 1 WHERE id IN (2, 3, 4)', $table));
echo "ready\n";
usleep(300_000);
try {
    $pdo->exec(sprintf('UPDATE %s SET v = v + 1 WHERE id = 1', $table));
    $pdo->exec('COMMIT');
    echo "done\n";
} catch (PDOException $e) {
    $pdo->exec('ROLLBACK');
    echo 'failed '.(is_array($e->errorInfo) && is_string($e->errorInfo[0] ?? null) ? $e->errorInfo[0] : '')."\n";
}
