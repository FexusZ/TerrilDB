<?php

declare(strict_types=1);

namespace App\Driver;

use App\Core\Query\CellValue;
use App\Core\Query\Exception\QueryTimeoutException;
use App\Core\Query\QueryResult;

/**
 * Lit un résultat PDO jusqu'à la limite demandée. Les lignes au-delà sont écartées, sans être converties.
 */
final class PdoRows
{
    /**
     * @return list<string>
     */
    public static function columns(\PDOStatement $statement): array
    {
        $columns = [];
        for ($i = 0, $count = $statement->columnCount(); $i < $count; ++$i) {
            $meta = $statement->getColumnMeta($i);
            $columns[] = false === $meta ? '' : $meta['name'];
        }

        return $columns;
    }

    /**
     * Parcourt tout le résultat pour le compter, sans convertir ni garder plus de `maxRows` lignes. Le délai est
     * vérifié ici aussi : le moteur ne borne pas toujours l'envoi des lignes.
     */
    public static function measure(\PDOStatement $statement, int $maxRows, int $started, int $timeoutMs): QueryResult
    {
        $columns = self::columns($statement);
        $rows = [];
        $total = 0;
        try {
            while (false !== ($row = $statement->fetch(\PDO::FETCH_NUM))) {
                if (\count($rows) < $maxRows) {
                    $rows[] = array_map(CellValue::normalize(...), $row);
                }
                if (0 === ++$total % 1000 && self::elapsed($started) > $timeoutMs) {
                    throw QueryTimeoutException::afterMilliseconds($timeoutMs);
                }
            }
        } finally {
            $statement->closeCursor();
        }

        return QueryResult::rows($columns, $rows, $total > $maxRows, self::elapsed($started), $total);
    }

    public static function read(\PDOStatement $statement, int $maxRows, int $started): QueryResult
    {
        $columns = self::columns($statement);

        $rows = [];
        $truncated = false;
        try {
            while (false !== ($row = $statement->fetch(\PDO::FETCH_NUM))) {
                if (\count($rows) >= $maxRows) {
                    $truncated = true;
                    break;
                }
                $rows[] = array_map(CellValue::normalize(...), $row);
            }
        } finally {
            // Indispensable en lecture non tamponnée (MySQL) : le reste du résultat doit être écarté avant l'instruction suivante.
            $statement->closeCursor();
        }

        return QueryResult::rows($columns, $rows, $truncated, self::elapsed($started));
    }

    public static function elapsed(int $started): int
    {
        return intdiv(hrtime(true) - $started, 1_000_000);
    }
}
