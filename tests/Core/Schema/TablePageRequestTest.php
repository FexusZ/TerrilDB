<?php

declare(strict_types=1);

namespace App\Tests\Core\Schema;

use App\Core\Schema\Exception\InvalidSchemaException;
use App\Core\Schema\TableKind;
use App\Core\Schema\TablePage;
use App\Core\Schema\TablePageRequest;
use App\Core\Schema\TableSummary;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class TablePageRequestTest extends TestCase
{
    public function testDefaultsToAHundredFromTheStartWithoutSearch(): void
    {
        $request = new TablePageRequest();

        self::assertSame(100, $request->limit);
        self::assertNull($request->after);
        self::assertNull($request->search);
    }

    public function testKeepsWhatWasGiven(): void
    {
        $request = new TablePageRequest(500, 'orders', 'ord');

        self::assertSame(500, $request->limit);
        self::assertSame('orders', $request->after);
        self::assertSame('ord', $request->search);
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function badLimits(): iterable
    {
        yield 'zero' => [0];
        yield 'negative' => [-1];
        yield 'above the ceiling' => [501];
    }

    #[DataProvider('badLimits')]
    public function testTheLimitIsBounded(int $limit): void
    {
        $this->expectException(InvalidSchemaException::class);

        new TablePageRequest($limit);
    }

    public function testABlankSearchMeansNoSearch(): void
    {
        self::assertNull((new TablePageRequest(10, null, '   '))->search);
        self::assertSame('a b', (new TablePageRequest(10, null, ' a b '))->search);
    }

    public function testAPageKeepsItsTablesAndPositions(): void
    {
        $table = new TableSummary('orders', TableKind::Table, 3);

        $page = new TablePage([$table], 'orders', 12);

        self::assertSame([$table], $page->tables);
        self::assertSame('orders', $page->nextCursor);
        self::assertSame(12, $page->total);
    }
}
