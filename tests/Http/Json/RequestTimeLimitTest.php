<?php

declare(strict_types=1);

namespace App\Tests\Http\Json;

use App\Core\Query\Exception\InvalidQueryOptionsException;
use App\Http\Json\RequestTimeLimit;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RequestTimeLimitTest extends TestCase
{
    public function testTheLimitIsTheProxyTimeoutMinusAMargin(): void
    {
        self::assertSame(100_000, (new RequestTimeLimit(120))->maxMs());
        self::assertSame(280_000, (new RequestTimeLimit(300))->maxMs());
    }

    public function testAnAbsentDurationFallsBackToTheDefaultWithoutExceedingTheLimit(): void
    {
        self::assertSame(30_000, (new RequestTimeLimit(120))->timeout(null, 30_000));
        self::assertSame(40_000, (new RequestTimeLimit(60))->timeout(null, 100_000), 'A default above the limit is brought back to it.');
    }

    public function testADurationWithinTheLimitIsKept(): void
    {
        self::assertSame(100_000, (new RequestTimeLimit(120))->timeout(100_000, 30_000));
        self::assertSame(250, (new RequestTimeLimit(120))->timeout(250, 30_000));
    }

    public function testADurationAboveTheLimitIsRefusedInsteadOfLettingTheProxyCutTheAnswer(): void
    {
        $this->expectException(InvalidQueryOptionsException::class);

        (new RequestTimeLimit(120))->timeout(100_001, 30_000);
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function unusableTimeouts(): iterable
    {
        yield 'zero' => [0];
        yield 'negative' => [-5];
        yield 'below the margin' => [20];
        yield 'above an hour' => [3601];
    }

    #[DataProvider('unusableTimeouts')]
    public function testAProxyTimeoutThatCannotLeaveRoomForAQueryStopsTheApplication(int $seconds): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new RequestTimeLimit($seconds);
    }
}
