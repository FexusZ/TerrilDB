<?php

declare(strict_types=1);

namespace App\Driver;

use App\Core\Connection\Engine;
use App\Core\Schema\Change\DdlExecutor;
use App\Core\Schema\Change\Exception\SchemaChangeFailedException;
use Doctrine\DBAL\Connection;

/**
 * Exécute des instructions de structure sur PostgreSQL, MySQL ou MariaDB, en connexion native. PostgreSQL : une transaction, donc
 * tout ou rien ; les autres : chaque instruction est validée par le moteur dès qu'elle passe. Un délai d'attente de verrou borne
 * l'attente quand la table est utilisée ailleurs, pour ne pas bloquer la connexion sans fin.
 */
final readonly class DbalDdlExecutor implements DdlExecutor
{
    public function __construct(private Connection $connection, private Engine $engine)
    {
    }

    public function execute(array $statements, bool $transactional): void
    {
        $pdo = $this->connection->getNativeConnection();
        if (!$pdo instanceof \PDO) {
            throw new \LogicException('The structure editor needs a PDO connection.');
        }

        $applied = [];
        $current = null;
        try {
            if ($transactional) {
                $pdo->exec('BEGIN');
            }
            $pdo->exec(Engine::PostgreSql === $this->engine ? "SET LOCAL lock_timeout = '10s'" : 'SET SESSION lock_wait_timeout = 10');
            foreach ($statements as $statement) {
                $current = $statement;
                $pdo->exec($statement->sql);
                $applied[] = $statement;
            }
            if ($transactional) {
                $pdo->exec('COMMIT');
            }
        } catch (\PDOException $e) {
            if ($transactional) {
                try {
                    $pdo->exec('ROLLBACK');
                } catch (\Throwable) {
                    // Connexion perdue : l'appelant la ferme, le moteur annule seul.
                }
                $applied = [];
            }
            $info = \is_array($e->errorInfo) ? $e->errorInfo : [];
            $message = \is_string($info[2] ?? null) ? $info[2] : $e->getMessage();

            // MySQL et MariaDB : 1205 (ER_LOCK_WAIT_TIMEOUT) ; PostgreSQL : SQLSTATE 55P03 (lock_not_available).
            $lockTimeout = Engine::PostgreSql === $this->engine ? '55P03' === ($info[0] ?? null) : 1205 === ($info[1] ?? null);

            throw new SchemaChangeFailedException(trim((string) preg_replace('/^ERROR:\s+/', '', $message)), $applied, $current ?? $statements[0], $e, $lockTimeout);
        }
    }
}
