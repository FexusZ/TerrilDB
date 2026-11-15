<?php

declare(strict_types=1);

namespace App\Tests\Core\Query;

use App\Core\Query\Exception\InvalidQueryOptionsException;
use App\Core\Query\QueryMode;
use App\Core\Query\QueryOptions;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class QueryOptionsTest extends TestCase
{
    public function testDefaultsAreReadOnlyWithBoundedTimeAndRows(): void
    {
        $options = new QueryOptions();

        self::assertSame(QueryMode::ReadOnly, $options->mode);
        self::assertSame(30_000, $options->timeoutMs);
        self::assertSame(1_000, $options->maxRows);
    }

    public function testEveryWriteRightIsGrantedByDefaultButOnlyMattersInReadWriteMode(): void
    {
        $options = new QueryOptions();

        self::assertTrue($options->writes->data);
        self::assertTrue($options->writes->schema);
    }

    public function testKeepsWhatWasGiven(): void
    {
        $options = new QueryOptions(QueryMode::ReadWrite, 5_000, 50);

        self::assertSame(QueryMode::ReadWrite, $options->mode);
        self::assertSame(5_000, $options->timeoutMs);
        self::assertSame(50, $options->maxRows);
    }

    /**
     * @return iterable<string, array{int, int}>
     */
    public static function outOfBounds(): iterable
    {
        yield 'timeout too short' => [99, 10];
        yield 'timeout too long' => [600_001, 10];
        yield 'no rows' => [1_000, 0];
        yield 'negative rows' => [1_000, -1];
        yield 'too many rows' => [1_000, 100_001];
    }

    #[DataProvider('outOfBounds')]
    public function testRefusesLimitsOutOfBounds(int $timeoutMs, int $maxRows): void
    {
        $this->expectException(InvalidQueryOptionsException::class);

        new QueryOptions(QueryMode::ReadOnly, $timeoutMs, $maxRows);
    }

    public function testMeasuringIsOffByDefault(): void
    {
        self::assertFalse((new QueryOptions())->measure);
    }
}
