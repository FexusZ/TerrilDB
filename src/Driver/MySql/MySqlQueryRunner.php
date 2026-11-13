<?php

declare(strict_types=1);

namespace App\Driver\MySql;

use App\Core\Connection\Engine;
use App\Core\Driver\Exception\LockConflictException;
use App\Core\Guard\StatementKind;
use App\Core\Query\Exception\QueryFailedException;
use App\Core\Query\Exception\QueryTimeoutException;
use App\Core\Query\QueryMode;
use App\Core\Query\QueryOptions;
use App\Core\Query\QueryResult;
use App\Core\Query\QueryRunner;
use App\Driver\EngineLockConflict;
use App\Driver\PdoRows;
use Doctrine\DBAL\Connection;

/**
 * Exécution sur MySQL et MariaDB, en connexion native (PDO) non tamponnée : seules `maxRows + 1` lignes sont
 * lues, le reste est écarté.
 *
 * La lecture seule est posée au niveau de la SESSION (`SET SESSION TRANSACTION READ ONLY`) et pas seulement
 * de la transaction : `START TRANSACTION READ ONLY` laisserait passer un CREATE ou un DROP, qui valident
 * implicitement la transaction avant de s'exécuter. Une lecture est toujours annulée.
 *
 * Délais : MAX_EXECUTION_TIME (MySQL, SELECT seulement) ou max_statement_time (MariaDB, toute instruction).
 * Limite connue : sur MySQL, une écriture n'a pas de délai côté moteur.
 */
final readonly class MySqlQueryRunner implements QueryRunner
{
    /** 3024 : MySQL, 1969 : MariaDB (« maximum statement execution time exceeded »). */
    private const TIMEOUT_CODES = [3024, 1969];

    public function __construct(
        private Connection $connection,
        private Engine $engine,
    ) {
    }

    public function run(string $sql, StatementKind $kind, QueryOptions $options): QueryResult
    {
        $pdo = $this->pdo();
        $readOnly = QueryMode::ReadOnly === $options->mode;
        $started = hrtime(true);

        try {
            $pdo->exec('SET SESSION TRANSACTION '.($readOnly ? 'READ ONLY' : 'READ WRITE'));
            $pdo->exec($this->timeoutStatement($options->timeoutMs));
            $pdo->exec('START TRANSACTION');

            $statement = $pdo->query($sql);
            \assert($statement instanceof \PDOStatement);
            $result = $statement->columnCount() > 0
                ? PdoRows::read($statement, $options->maxRows, $started)
                : QueryResult::affected($statement->rowCount(), PdoRows::elapsed($started));

            $pdo->exec($readOnly ? 'ROLLBACK' : 'COMMIT');

            return $result;
        } catch (\PDOException $e) {
            $this->rollBack($pdo);

            throw $this->translate($e, $options);
        } catch (\Throwable $e) {
            $this->rollBack($pdo);

            throw $e;
        }
    }

    private function pdo(): \PDO
    {
        $native = $this->connection->getNativeConnection();

        return $native instanceof \PDO ? $native : throw new \LogicException('MySQL runner needs a PDO connection.');
    }

    private function timeoutStatement(int $timeoutMs): string
    {
        return Engine::MariaDb === $this->engine
            ? \sprintf('SET SESSION max_statement_time = %s', number_format($timeoutMs / 1000, 3, '.', ''))
            : \sprintf('SET SESSION MAX_EXECUTION_TIME = %d', $timeoutMs);
    }

    private function rollBack(\PDO $pdo): void
    {
        try {
            $pdo->exec('ROLLBACK');
        } catch (\Throwable) {
            // La connexion est peut-être perdue : l'appelant la fermera.
        }
    }

    private function translate(\PDOException $e, QueryOptions $options): \RuntimeException
    {
        $info = \is_array($e->errorInfo) ? $e->errorInfo : [];
        if (\in_array($info[1] ?? null, self::TIMEOUT_CODES, true)) {
            return QueryTimeoutException::afterMilliseconds($options->timeoutMs, $e);
        }

        $lock = EngineLockConflict::of($this->engine, $e);
        if (null !== $lock) {
            return new LockConflictException($lock, $e);
        }

        return new QueryFailedException(trim(\is_string($info[2] ?? null) ? $info[2] : $e->getMessage()), previous: $e);
    }
}
