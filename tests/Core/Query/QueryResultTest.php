<?php

declare(strict_types=1);

namespace App\Tests\Core\Query;

use App\Core\Query\QueryResult;
use PHPUnit\Framework\TestCase;

final class QueryResultTest extends TestCase
{
    public function testARowSetKnowsItsShape(): void
    {
        $result = QueryResult::rows(['id', 'name'], [[1, 'a'], [2, null]], true, 12);

        self::assertSame(['id', 'name'], $result->columns);
        self::assertSame([[1, 'a'], [2, null]], $result->rows);
        self::assertSame(2, $result->rowCount());
        self::assertTrue($result->truncated);
        self::assertNull($result->affectedRows);
        self::assertSame(12, $result->durationMs);
        self::assertTrue($result->returnsRows());
    }

    public function testAWriteReportsAffectedRows(): void
    {
        $result = QueryResult::affected(3, 7);

        self::assertSame([], $result->columns);
        self::assertSame([], $result->rows);
        self::assertSame(3, $result->affectedRows);
        self::assertFalse($result->truncated);
        self::assertFalse($result->returnsRows());
    }

    public function testAQueryWithColumnsButNoRowsStillReturnsRows(): void
    {
        $result = QueryResult::rows(['id'], [], false, 1);

        self::assertTrue($result->returnsRows());
        self::assertSame(0, $result->rowCount());
    }
}
