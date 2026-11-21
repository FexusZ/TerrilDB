<?php

declare(strict_types=1);

namespace App\Tests\ScriptRun;

use App\Core\Query\Exception\InvalidQueryOptionsException;
use App\ScriptRun\ScriptRunTimeLimit;
use PHPUnit\Framework\TestCase;

final class ScriptRunTimeLimitTest extends TestCase
{
    public function testTheMaximumComesFromTheSettingInSeconds(): void
    {
        self::assertSame(14_400_000, (new ScriptRunTimeLimit(14_400))->maxMs());
        self::assertSame(3_600_000, (new ScriptRunTimeLimit(3_600))->maxMs());
    }

    public function testTheDefaultIsTenMinutesOrTheMaximumIfLower(): void
    {
        self::assertSame(600_000, (new ScriptRunTimeLimit(14_400))->defaultMs());
        self::assertSame(600_000, (new ScriptRunTimeLimit(600))->defaultMs());
    }

    public function testADelayAboveTheMaximumOrBelowTheMinimumIsRefused(): void
    {
        $limit = new ScriptRunTimeLimit(7_200);

        self::assertSame(7_200_000, $limit->check(7_200_000));
        foreach ([7_200_001, 99, 0, -5] as $ms) {
            try {
                $limit->check($ms);
                self::fail("$ms ms must be refused");
            } catch (InvalidQueryOptionsException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function testTheSettingItselfIsBounded(): void
    {
        foreach ([0, 599, 86_401] as $seconds) {
            try {
                new ScriptRunTimeLimit($seconds);
                self::fail("$seconds s must be refused");
            } catch (\InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
    }
}
