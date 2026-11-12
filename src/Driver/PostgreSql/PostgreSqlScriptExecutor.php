<?php

declare(strict_types=1);

namespace App\Driver\PostgreSql;

use App\Driver\PdoScriptExecutor;

/**
 * PostgreSQL range le DDL dans la transaction : tout le script, structure comprise, est tout ou rien.
 */
final class PostgreSqlScriptExecutor extends PdoScriptExecutor
{
    private const TIMEOUT_SQLSTATE = '57014';

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
