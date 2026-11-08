<?php

declare(strict_types=1);

namespace App\Driver;

use App\Core\Data\DataExecutor;
use App\Core\Data\DataPlan;
use App\Core\Data\DataStatement;
use App\Core\Data\Exception\DataChangeConflictException;
use App\Core\Data\Exception\DataChangeFailedException;
use Doctrine\DBAL\Connection;

/**
 * Écrit sur PostgreSQL, MySQL ou MariaDB : une transaction, des paramètres liés, et une modification ou une suppression qui
 * ne touche pas exactement une ligne annule tout.
 */
final readonly class DbalDataExecutor implements DataExecutor
{
    public function __construct(private Connection $connection)
    {
    }

    public function matching(DataStatement $statement): int
    {
        try {
            $count = $this->connection->fetchOne($statement->matchSql ?? '', array_values($statement->matchParams));

            return is_numeric($count) ? (int) $count : 0;
        } catch (\Throwable $e) {
            throw new DataChangeFailedException(self::message($e), 0, $statement, $e);
        }
    }

    public function execute(DataPlan $plan): void
    {
        $this->connection->beginTransaction();
        $current = $plan->statements[0];
        try {
            foreach ($plan->statements as $index => $statement) {
                $current = $statement;
                $affected = (int) $this->connection->executeStatement($statement->sql, array_values($statement->params));
                if ('insert' !== $statement->operation && 1 !== $affected) {
                    throw new DataChangeConflictException($index, $affected);
                }
            }
            $this->connection->commit();
        } catch (DataChangeConflictException $e) {
            $this->rollBack();

            throw $e;
        } catch (\Throwable $e) {
            $this->rollBack();

            throw new DataChangeFailedException(self::message($e), 0, $current, $e);
        }
    }

    private function rollBack(): void
    {
        try {
            $this->connection->rollBack();
        } catch (\Throwable) {
            // Connexion perdue : l'appelant la ferme, le moteur annule seul.
        }
    }

    private static function message(\Throwable $e): string
    {
        $previous = $e->getPrevious() ?? $e;
        $message = $previous instanceof \PDOException && \is_array($previous->errorInfo) && \is_string($previous->errorInfo[2] ?? null) ? $previous->errorInfo[2] : $previous->getMessage();

        return trim((string) preg_replace('/^ERROR:\s+/', '', $message));
    }
}
