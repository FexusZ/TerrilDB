<?php

declare(strict_types=1);

namespace App\Driver\MySql;

use App\Core\Connection\Engine;
use App\Driver\PdoScriptExecutor;
use Doctrine\DBAL\Connection;

/**
 * MySQL et MariaDB : une transaction pour les données ; un DDL (ou CALL, DO) valide tout ce qui précède puis lui-même,
 * ce que le compte rendu reflète. Limite connue : MySQL n'a pas de délai moteur pour une écriture.
 */
final class MySqlScriptExecutor extends PdoScriptExecutor
{
    /** 3024 : MySQL, 1969 : MariaDB. */
    private const TIMEOUT_CODES = [3024, 1969];

    public function __construct(Connection $connection, private readonly Engine $engine)
    {
        parent::__construct($connection);
    }

    /** Au-delà, la liste est tronquée : un serveur n'a pas des milliers de tables sans transaction. */
    private const MAX_TABLES = 5000;
    private const TRANSACTIONAL_ENGINES = ['INNODB', 'NDB', 'NDBCLUSTER', 'ROCKSDB', 'TOKUDB', 'XTRADB'];

    public function nonTransactionalTables(): array
    {
        $names = $this->connection->fetchFirstColumn(\sprintf(
            "SELECT DISTINCT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_TYPE = 'BASE TABLE' AND ENGINE IS NOT NULL AND UPPER(ENGINE) NOT IN ('%s') "
            ."AND TABLE_SCHEMA NOT IN ('mysql', 'information_schema', 'performance_schema', 'sys') LIMIT %d",
            implode("', '", self::TRANSACTIONAL_ENGINES),
            self::MAX_TABLES,
        ));

        return array_values(array_filter(array_map(static fn (mixed $name): string => \is_string($name) ? $name : '', $names), static fn (string $name): bool => '' !== $name));
    }

    protected function begin(\PDO $pdo): void
    {
        $pdo->exec('SET SESSION TRANSACTION READ WRITE');
        $pdo->exec('START TRANSACTION');
    }

    protected function limit(\PDO $pdo, int $remainingMs): void
    {
        $pdo->exec(Engine::MariaDb === $this->engine
            ? \sprintf('SET SESSION max_statement_time = %s', number_format($remainingMs / 1000, 3, '.', ''))
            : \sprintf('SET SESSION MAX_EXECUTION_TIME = %d', $remainingMs));
    }

    protected function isTimeout(\PDOException $e): bool
    {
        return \is_array($e->errorInfo) && \in_array($e->errorInfo[1] ?? null, self::TIMEOUT_CODES, true);
    }
}
