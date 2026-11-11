<?php

declare(strict_types=1);

namespace App\Tests\Core\Schema\Change;

use App\Core\Schema\Change\BigTableLevel;
use App\Core\Schema\Change\BigTablePolicy;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class BigTablePolicyTest extends TestCase
{
    /**
     * @return iterable<string, array{?int, BigTableLevel}>
     */
    public static function levels(): iterable
    {
        yield 'unknown estimate' => [null, BigTableLevel::None];
        yield 'empty' => [0, BigTableLevel::None];
        yield 'just under the warning' => [99, BigTableLevel::None];
        yield 'at the warning' => [100, BigTableLevel::Warning];
        yield 'just under the name check' => [999, BigTableLevel::Warning];
        yield 'at the name check' => [1000, BigTableLevel::ConfirmName];
        yield 'huge' => [5_000_000_000, BigTableLevel::ConfirmName];
    }

    #[DataProvider('levels')]
    public function testLevelFollowsTheEstimate(?int $rows, BigTableLevel $expected): void
    {
        self::assertSame($expected, (new BigTablePolicy(100, 1000))->assess($rows)->level);
    }

    public function testAssessmentCarriesTheFigures(): void
    {
        $assessment = (new BigTablePolicy(100, 1000))->assess(250);

        self::assertSame(250, $assessment->estimatedRows);
        self::assertSame(100, $assessment->warnAt);
        self::assertSame(1000, $assessment->confirmAt);
    }

    public function testZeroDisablesEachThreshold(): void
    {
        self::assertSame(BigTableLevel::Warning, (new BigTablePolicy(100, 0))->assess(10_000_000)->level);
        self::assertSame(BigTableLevel::ConfirmName, (new BigTablePolicy(0, 1000))->assess(5000)->level);
        self::assertSame(BigTableLevel::None, (new BigTablePolicy(0, 0))->assess(10_000_000)->level);
    }

    public function testNegativeThresholdsAreRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new BigTablePolicy(-1, 0);
    }
}
