<?php

declare(strict_types=1);

namespace App\Driver;

use App\Core\Query\CellValue;
use App\Core\Query\QueryResult;

/**
 * Lit un résultat PDO jusqu'à la limite demandée. Les lignes au-delà sont écartées, sans être converties.
 */
final class PdoRows
{
    public static function read(\PDOStatement $statement, int $maxRows, int $started): QueryResult
    {
        $columns = [];
        for ($i = 0, $count = $statement->columnCount(); $i < $count; ++$i) {
            $meta = $statement->getColumnMeta($i);
            $columns[] = false === $meta ? '' : $meta['name'];
        }

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
