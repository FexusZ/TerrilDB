<?php

declare(strict_types=1);

namespace App\Tests\Core\Diagnostics;

use App\Core\Diagnostics\DigestOrder;
use App\Core\Diagnostics\DigestQuery;
use App\Core\Diagnostics\Exception\InvalidDigestQueryException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DigestQueryTest extends TestCase
{
    public function testDefaultsAreTotalTimeAndTwenty(): void
    {
        $query = DigestQuery::fromInput(null, null);

        self::assertSame(DigestOrder::TotalTime, $query->order);
        self::assertSame(20, $query->limit);
    }

    public function testReadsTheOrderAndTheLimit(): void
    {
        $query = DigestQuery::fromInput('calls', '100');

        self::assertSame(DigestOrder::Calls, $query->order);
        self::assertSame(100, $query->limit);
        self::assertSame(1, DigestQuery::fromInput(null, '1')->limit);
    }

    /**
     * @return iterable<string, array{?string, ?string}>
     */
    public static function invalid(): iterable
    {
        yield 'unknown order' => ['slowest; DROP TABLE x', null];
        yield 'zero' => [null, '0'];
        yield 'too many' => [null, '101'];
        yield 'negative' => [null, '-3'];
        yield 'text' => [null, 'ten'];
        yield 'decimal' => [null, '1.5'];
    }

    #[DataProvider('invalid')]
    public function testRefusesWhatIsOutOfBounds(?string $order, ?string $limit): void
    {
        $this->expectException(InvalidDigestQueryException::class);

        DigestQuery::fromInput($order, $limit);
    }
}
