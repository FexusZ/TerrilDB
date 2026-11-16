<?php

declare(strict_types=1);

namespace App\Http\Json;

use App\Core\Query\QueryResult;

final class ResultPresenter
{
    /**
     * @return array<string, mixed>
     */
    public static function present(QueryResult $result): array
    {
        return (null === $result->totalRows ? [] : ['totalRows' => $result->totalRows]) + [
            'columns' => $result->columns,
            'rows' => $result->rows,
            'rowCount' => $result->rowCount(),
            'truncated' => $result->truncated,
            'affectedRows' => $result->affectedRows,
            'durationMs' => $result->durationMs,
        ];
    }
}
