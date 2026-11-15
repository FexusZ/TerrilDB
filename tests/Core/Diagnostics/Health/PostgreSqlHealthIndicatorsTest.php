<?php

declare(strict_types=1);

namespace App\Tests\Core\Diagnostics\Health;

use App\Core\Diagnostics\Health\HealthIndicator;
use App\Core\Diagnostics\Health\HealthLevel;
use App\Core\Diagnostics\Health\HealthSample;
use App\Core\Diagnostics\Health\HealthWindow;
use App\Core\Diagnostics\Health\PostgreSqlHealthIndicators;
use PHPUnit\Framework\TestCase;

final class PostgreSqlHealthIndicatorsTest extends TestCase
{
    /**
     * @param array<string, int> $before
     * @param array<string, int> $after
     *
     * @return array<string, HealthIndicator>
     */
    private static function indicators(array $before, array $after, float $seconds = 10.0): array
    {
        $window = new HealthWindow(
            new HealthSample(0.0, array_map(strval(...), $before), 100, 'epoch'),
            new HealthSample($seconds, array_map(strval(...), $after), 110, 'epoch'),
        );

        $byKey = [];
        foreach (PostgreSqlHealthIndicators::compute($window) as $indicator) {
            $byKey[$indicator->key] = $indicator;
        }

        return $byKey;
    }

    public function testTransactionsPerSecondAndRollbackRatio(): void
    {
        $indicators = self::indicators(['xact_commit' => 1000, 'xact_rollback' => 10], ['xact_commit' => 1800, 'xact_rollback' => 210]);

        self::assertSame(100.0, $indicators['transactions_per_second']->value);
        self::assertSame(20.0, $indicators['rollback_percent']->value);
        self::assertSame(HealthLevel::Warning, $indicators['rollback_percent']->level);
    }

    public function testCacheHitRatioOnTheInterval(): void
    {
        $indicators = self::indicators(['blks_hit' => 1_000_000, 'blks_read' => 0], ['blks_hit' => 1_009_000, 'blks_read' => 1_000]);

        self::assertSame(90.0, $indicators['cache_hit_percent']->value);
        self::assertSame(HealthLevel::Warning, $indicators['cache_hit_percent']->level);
    }

    public function testConnectionsAgainstTheCeiling(): void
    {
        $indicators = self::indicators([], ['connections' => 5, 'max_connections' => 100]);

        self::assertSame(5.0, $indicators['connections_used_percent']->value);
        self::assertSame(HealthLevel::Ok, $indicators['connections_used_percent']->level);
    }

    public function testTemporaryFilesAndDeadlocks(): void
    {
        $indicators = self::indicators(['temp_files' => 0, 'deadlocks' => 0], ['temp_files' => 50, 'deadlocks' => 1]);

        self::assertSame(5.0, $indicators['temp_files_per_second']->value);
        self::assertSame(HealthLevel::Warning, $indicators['temp_files_per_second']->level);
        self::assertSame(1.0, $indicators['deadlocks']->value);
        self::assertSame(HealthLevel::Warning, $indicators['deadlocks']->level);
    }

    public function testAbsentCountersGiveUnknownIndicators(): void
    {
        foreach (self::indicators([], []) as $indicator) {
            self::assertNull($indicator->value);
            self::assertSame(HealthLevel::Unknown, $indicator->level);
        }
    }
}
