<?php

declare(strict_types=1);

namespace App\Driver\MySql;

use App\Core\Connection\Engine;
use App\Core\Guard\StatementKind;
use App\Core\Query\Exception\QueryFailedException;
use App\Core\Query\Exception\QueryTimeoutException;
use App\Core\Query\QueryMode;
use App\Core\Query\QueryOptions;
use App\Core\Query\QueryResult;
use App\Core\Query\QueryRunner;
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
 *
 * Sur Vitess (détecté à l'ouverture, voir `forConnection`), la variable de session n'est pas transmise : le délai est
 * injecté dans l'instruction (`VitessTimeout`), et vtgate répond par la même erreur 3024.
 */
final readonly class MySqlQueryRunner implements QueryRunner
{
    /** 3024 : MySQL, 1969 : MariaDB (« maximum statement execution time exceeded »). */
    private const TIMEOUT_CODES = [3024, 1969];

    public function __construct(
        private Connection $connection,
        private Engine $engine,
        private bool $vitess = false,
    ) {
    }

    /**
     * Lit `@@version` une fois pour reconnaître Vitess (MySQL seulement : MariaDB n'est jamais derrière vtgate).
     */
    public static function forConnection(Connection $connection, Engine $engine): self
    {
        $vitess = false;
        if (Engine::MySql === $engine) {
            try {
                $version = $connection->fetchOne('SELECT @@version');
                $vitess = \is_string($version) && VitessTimeout::isVitessVersion($version);
            } catch (\Throwable) {
                // Version illisible : comportement MySQL ordinaire.
            }
        }

        return new self($connection, $engine, $vitess);
    }

    public function run(string $sql, StatementKind $kind, QueryOptions $options): QueryResult
    {
        $pdo = $this->pdo();
        $readOnly = QueryMode::ReadOnly === $options->mode;
        $started = hrtime(true);

        try {
            $pdo->exec('SET SESSION TRANSACTION '.($readOnly ? 'READ ONLY' : 'READ WRITE'));
            if (!$this->vitess) {
                $pdo->exec($this->timeoutStatement($options->timeoutMs));
            }
            $pdo->exec('START TRANSACTION');

            $statement = $pdo->query($this->vitess ? VitessTimeout::inject($sql, $options->timeoutMs) : $sql);
            \assert($statement instanceof \PDOStatement);
            $result = $statement->columnCount() > 0
                ? PdoRows::read($statement, $options->maxRows, $started)
                : QueryResult::affected($statement->rowCount(), PdoRows::elapsed($started));

            $pdo->exec($readOnly ? 'ROLLBACK' : 'COMMIT');

            return $result;
        } catch (\PDOException $e) {
            $this->rollBack($pdo);

            throw self::translate($e, $options);
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

    private static function translate(\PDOException $e, QueryOptions $options): \RuntimeException
    {
        $info = \is_array($e->errorInfo) ? $e->errorInfo : [];
        if (\in_array($info[1] ?? null, self::TIMEOUT_CODES, true)) {
            return QueryTimeoutException::afterMilliseconds($options->timeoutMs, $e);
        }

        return new QueryFailedException(trim(\is_string($info[2] ?? null) ? $info[2] : $e->getMessage()), previous: $e);
    }
}
