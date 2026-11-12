<?php

declare(strict_types=1);

namespace App\Driver\PostgreSql;

use App\Driver\PdoScriptExecutor;

/**
 * PostgreSQL range le DDL dans la transaction : tout le script, structure comprise, est tout ou rien. Une lecture passe par un
 * curseur serveur (1001 lignes au plus rapatriées) : libpq tamponne sinon tout le résultat avant de le rendre.
 */
final class PostgreSqlScriptExecutor extends PdoScriptExecutor
{
    private const TIMEOUT_SQLSTATE = '57014';

    private const CURSOR = 'terrildb_script_read';

    protected function openRead(\PDO $pdo, string $sql, int $limit): \PDOStatement|false
    {
        $pdo->exec(\sprintf('DECLARE %s NO SCROLL CURSOR FOR %s', self::CURSOR, $sql));

        return $pdo->query(\sprintf('FETCH FORWARD %d FROM %s', $limit, self::CURSOR));
    }

    protected function closeRead(\PDO $pdo): void
    {
        $pdo->exec('CLOSE '.self::CURSOR);
    }

    protected function begin(\PDO $pdo): void
    {
        $pdo->exec('BEGIN');
    }

    protected function limit(\PDO $pdo, int $remainingMs): void
    {
        $pdo->exec(\sprintf('SET LOCAL statement_timeout = %d', $remainingMs));
    }

    protected function isTimeout(\PDOException $e): bool
    {
        return \is_array($e->errorInfo) && self::TIMEOUT_SQLSTATE === ($e->errorInfo[0] ?? null);
    }
}
