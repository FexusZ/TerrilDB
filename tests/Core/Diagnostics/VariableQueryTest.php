<?php

declare(strict_types=1);

namespace App\Tests\Core\Diagnostics;

use App\Core\Diagnostics\Exception\InvalidVariableQueryException;
use App\Core\Diagnostics\VariableQuery;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class VariableQueryTest extends TestCase
{
    public function testDefaults(): void
    {
        $query = new VariableQuery();

        self::assertNull($query->search);
        self::assertFalse($query->changedOnly);
        self::assertSame(VariableQuery::DEFAULT_LIMIT, $query->limit);
    }

    public function testTheSearchIsTrimmedAndEmptyMeansNone(): void
    {
        self::assertSame('buffer', (new VariableQuery(search: '  buffer '))->search);
        self::assertNull((new VariableQuery(search: '   '))->search);
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function badLimits(): iterable
    {
        yield 'zero' => [0];
        yield 'negative' => [-1];
        yield 'too many' => [VariableQuery::MAX_LIMIT + 1];
    }

    #[DataProvider('badLimits')]
    public function testTheLimitIsBounded(int $limit): void
    {
        $this->expectException(InvalidVariableQueryException::class);

        new VariableQuery(limit: $limit);
    }

    public function testTheSearchIsBounded(): void
    {
        $this->expectException(InvalidVariableQueryException::class);

        new VariableQuery(search: str_repeat('a', VariableQuery::MAX_SEARCH_LENGTH + 1));
    }
}
