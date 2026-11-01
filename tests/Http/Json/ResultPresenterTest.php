<?php

declare(strict_types=1);

namespace App\Tests\Http\Json;

use App\Core\Query\QueryResult;
use App\Http\Json\ResultPresenter;
use PHPUnit\Framework\TestCase;

final class ResultPresenterTest extends TestCase
{
    public function testPresentsARowSet(): void
    {
        $result = QueryResult::rows(['id', 'name'], [[1, 'a'], [2, null]], true, 12);

        self::assertSame([
            'columns' => ['id', 'name'],
            'rows' => [[1, 'a'], [2, null]],
            'rowCount' => 2,
            'truncated' => true,
            'affectedRows' => null,
            'durationMs' => 12,
        ], ResultPresenter::present($result));
    }

    public function testPresentsAWrite(): void
    {
        self::assertSame([
            'columns' => [],
            'rows' => [],
            'rowCount' => 0,
            'truncated' => false,
            'affectedRows' => 3,
            'durationMs' => 7,
        ], ResultPresenter::present(QueryResult::affected(3, 7)));
    }
}
