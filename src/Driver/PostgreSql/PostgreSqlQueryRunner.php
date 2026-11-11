<?php

declare(strict_types=1);

namespace App\Driver\PostgreSql;

use App\Core\Guard\StatementKind;
use App\Core\Query\CellValue;
use App\Core\Query\Exception\QueryFailedException;
use App\Core\Query\Exception\QueryTimeoutException;
use App\Core\Query\QueryMode;
use App\Core\Query\QueryOptions;
use App\Core\Query\QueryResult;
use App\Core\Query\QueryRunner;
use App\Driver\PdoRows;
use Doctrine\DBAL\Connection;

/**
 * Exécution sur PostgreSQL, en connexion native (PDO) : pas d'analyse de paramètres sur les lectures, donc
 * l'opérateur `?` de jsonb passe.
 *
 * Chaque instruction vit dans sa propre transaction. En lecture seule, c'est BEGIN READ ONLY, toujours annulée.
 * En lecture-écriture, validée si tout réussit, annulée sinon. Le délai est un `statement_timeout` local.
 * Les lectures passent par un curseur serveur, pour ne ramener que `maxRows + 1` lignes quelle que soit
 * la taille du résultat.
 */
final readonly class PostgreSqlQueryRunner implements QueryRunner
{
    private const CURSOR = 'terril_cursor';
    private const MEASURE_CHUNK = 1000;
    private const TIMEOUT_SQLSTATE = '57014';

    public function __construct(private Connection $connection)
    {
    }

    public function run(string $sql, StatementKind $kind, QueryOptions $options): QueryResult
    {
        $pdo = $this->pdo();
        $readOnly = QueryMode::ReadOnly === $options->mode;
        $started = hrtime(true);

        try {
            $pdo->exec($readOnly ? 'BEGIN READ ONLY' : 'BEGIN');
            $pdo->exec(\sprintf('SET LOCAL statement_timeout = %d', $options->timeoutMs));
            $result = StatementKind::Read === $kind
                ? $this->readThroughCursor($pdo, $sql, $options, $started)
                : $this->execute($pdo, $sql, $options, $started);
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

        return $native instanceof \PDO ? $native : throw new \LogicException('PostgreSQL runner needs a PDO connection.');
    }

    private function readThroughCursor(\PDO $pdo, string $sql, QueryOptions $options, int $started): QueryResult
    {
        $pdo->exec(\sprintf('DECLARE %s NO SCROLL CURSOR FOR %s', self::CURSOR, $sql));
        if ($options->measure) {
            return $this->measureThroughCursor($pdo, $options, $started);
        }
        $statement = $pdo->query(\sprintf('FETCH FORWARD %d FROM %s', $options->maxRows + 1, self::CURSOR));
        \assert($statement instanceof \PDOStatement);

        return PdoRows::read($statement, $options->maxRows, $started);
    }

    /**
     * Le curseur est vidé par tranches : ni le client ni PHP ne tiennent tout le résultat. Le `statement_timeout`
     * vaut pour chaque tranche, le délai total est donc vérifié entre deux.
     */
    private function measureThroughCursor(\PDO $pdo, QueryOptions $options, int $started): QueryResult
    {
        $columns = [];
        $rows = [];
        $total = 0;
        do {
            $statement = $pdo->query(\sprintf('FETCH FORWARD %d FROM %s', self::MEASURE_CHUNK, self::CURSOR));
            \assert($statement instanceof \PDOStatement);
            $columns = [] === $columns ? PdoRows::columns($statement) : $columns;
            $fetched = 0;
            while (false !== ($row = $statement->fetch(\PDO::FETCH_NUM))) {
                ++$fetched;
                if (\count($rows) < $options->maxRows) {
                    $rows[] = array_map(CellValue::normalize(...), $row);
                }
            }
            $statement->closeCursor();
            $total += $fetched;
            if (PdoRows::elapsed($started) > $options->timeoutMs) {
                throw QueryTimeoutException::afterMilliseconds($options->timeoutMs);
            }
        } while (self::MEASURE_CHUNK === $fetched);

        return QueryResult::rows($columns, $rows, $total > $options->maxRows, PdoRows::elapsed($started), $total);
    }

    private function execute(\PDO $pdo, string $sql, QueryOptions $options, int $started): QueryResult
    {
        $statement = $pdo->query($sql);
        \assert($statement instanceof \PDOStatement);

        return $statement->columnCount() > 0
            ? PdoRows::read($statement, $options->maxRows, $started)
            : QueryResult::affected($statement->rowCount(), PdoRows::elapsed($started));
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
        if (self::TIMEOUT_SQLSTATE === ($info[0] ?? null)) {
            return QueryTimeoutException::afterMilliseconds($options->timeoutMs, $e);
        }

        $message = \is_string($info[2] ?? null) ? $info[2] : $e->getMessage();

        return new QueryFailedException(trim((string) preg_replace('/^ERROR:\s+/', '', $message)), previous: $e);
    }
}
