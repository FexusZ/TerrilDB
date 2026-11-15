<?php

declare(strict_types=1);

namespace App\Tests\Core\Diagnostics\Health;

use App\Core\Diagnostics\Health\HealthSample;
use App\Core\Diagnostics\Health\HealthWindow;
use PHPUnit\Framework\TestCase;

final class HealthWindowTest extends TestCase
{
    /**
     * @param array<string, string> $counters
     */
    private static function sample(float $at, array $counters, ?int $uptime = 100, ?string $epoch = null): HealthSample
    {
        return new HealthSample($at, $counters, $uptime, $epoch);
    }

    public function testRateIsTheDeltaOverTheRealElapsedTime(): void
    {
        $window = new HealthWindow(self::sample(10.0, ['Questions' => '1000']), self::sample(15.0, ['Questions' => '1500'], 105));

        self::assertSame(5.0, $window->seconds());
        self::assertSame(500.0, $window->delta('Questions'));
        self::assertSame(100.0, $window->rate('Questions'));
        self::assertNull($window->resetReason());
    }

    public function testACounterThatRestartedFromZeroHasNoDelta(): void
    {
        $window = new HealthWindow(self::sample(0.0, ['Slow_queries' => '900', 'Questions' => '10']), self::sample(5.0, ['Slow_queries' => '3', 'Questions' => '20'], 105));

        self::assertNull($window->delta('Slow_queries'));
        self::assertNull($window->rate('Slow_queries'));
        self::assertSame(10.0, $window->delta('Questions'));
    }

    public function testNonNumericOrMissingValuesHaveNoDelta(): void
    {
        $window = new HealthWindow(self::sample(0.0, ['A' => 'ON', 'B' => '', 'C' => '1e3', 'D' => '1']), self::sample(5.0, ['A' => 'ON', 'B' => '4', 'C' => '2000', 'D' => '3'], 105));

        self::assertNull($window->delta('A'));
        self::assertNull($window->delta('B'));
        self::assertNull($window->delta('C'));
        self::assertNull($window->delta('absent'));
        self::assertSame(2.0, $window->delta('D'));
    }

    public function testADecreasingUptimeDiscardsTheWholeMeasurement(): void
    {
        $window = new HealthWindow(self::sample(0.0, ['Questions' => '9000'], 5000), self::sample(5.0, ['Questions' => '12'], 3));

        self::assertSame('counters_reset', $window->resetReason());
    }

    public function testAChangedStatisticsEpochDiscardsTheMeasurement(): void
    {
        $window = new HealthWindow(self::sample(0.0, [], 100, 'a'), self::sample(5.0, [], 105, 'b'));

        self::assertSame('counters_reset', $window->resetReason());
    }

    public function testAnIntervalWithoutDurationIsInvalid(): void
    {
        $window = new HealthWindow(self::sample(5.0, ['Questions' => '1']), self::sample(5.0, ['Questions' => '2']));

        self::assertSame('invalid_interval', $window->resetReason());
        self::assertNull($window->rate('Questions'));
    }

    public function testGaugeIsTheLatestValue(): void
    {
        $window = new HealthWindow(self::sample(0.0, ['Threads_connected' => '4']), self::sample(5.0, ['Threads_connected' => '9'], 105));

        self::assertSame(9.0, $window->gauge('Threads_connected'));
        self::assertNull($window->gauge('absent'));
    }
}
