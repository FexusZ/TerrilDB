<?php

declare(strict_types=1);

namespace App\Tests\Core\Diagnostics\Health;

use App\Core\Diagnostics\Health\HealthIndicator;
use App\Core\Diagnostics\Health\HealthLevel;
use App\Core\Diagnostics\Health\HealthSample;
use App\Core\Diagnostics\Health\HealthWindow;
use App\Core\Diagnostics\Health\MySqlHealthIndicators;
use PHPUnit\Framework\TestCase;

final class MySqlHealthIndicatorsTest extends TestCase
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
            new HealthSample(0.0, array_map(strval(...), $before), 100),
            new HealthSample($seconds, array_map(strval(...), $after), 100 + (int) $seconds),
        );

        $byKey = [];
        foreach (MySqlHealthIndicators::compute($window) as $indicator) {
            $byKey[$indicator->key] = $indicator;
        }

        return $byKey;
    }

    public function testQueriesPerSecondUsesTheDeltaOnly(): void
    {
        $indicators = self::indicators(['Questions' => 5_000_000], ['Questions' => 5_000_500]);

        self::assertSame(50.0, $indicators['queries_per_second']->value);
        self::assertSame('per_second', $indicators['queries_per_second']->unit);
        self::assertSame(HealthLevel::Ok, $indicators['queries_per_second']->level);
    }

    public function testBufferPoolHitRatioIsMeasuredOnTheInterval(): void
    {
        // Cumulé depuis le démarrage : 0,1 % de lectures disque ; sur l'intervalle : 5 %. Seul l'intervalle compte.
        $indicators = self::indicators(
            ['Innodb_buffer_pool_read_requests' => 1_000_000_000, 'Innodb_buffer_pool_reads' => 1_000_000],
            ['Innodb_buffer_pool_read_requests' => 1_000_010_000, 'Innodb_buffer_pool_reads' => 1_000_500],
        );

        self::assertSame(95.0, $indicators['buffer_pool_hit_percent']->value);
        self::assertSame(HealthLevel::Warning, $indicators['buffer_pool_hit_percent']->level);
    }

    public function testAFewRequestsNeverRaiseAWarning(): void
    {
        $indicators = self::indicators(
            ['Innodb_buffer_pool_read_requests' => 100, 'Innodb_buffer_pool_reads' => 0],
            ['Innodb_buffer_pool_read_requests' => 110, 'Innodb_buffer_pool_reads' => 10],
        );

        self::assertSame(0.0, $indicators['buffer_pool_hit_percent']->value);
        self::assertSame(HealthLevel::Ok, $indicators['buffer_pool_hit_percent']->level);
    }

    public function testConnectionsAgainstTheCeiling(): void
    {
        $indicators = self::indicators([], ['Threads_connected' => 90, 'max_connections' => 100, 'Threads_running' => 2]);

        self::assertSame(90.0, $indicators['connections_used_percent']->value);
        self::assertSame(HealthLevel::Warning, $indicators['connections_used_percent']->level);
        self::assertSame(2.0, $indicators['threads_running']->value);
        self::assertSame(HealthLevel::Ok, $indicators['threads_running']->level);
    }

    public function testAbortedConnectsRatio(): void
    {
        $indicators = self::indicators(['Connections' => 100, 'Aborted_connects' => 5], ['Connections' => 200, 'Aborted_connects' => 25]);

        self::assertSame(20.0, $indicators['aborted_connects_percent']->value);
        self::assertSame(HealthLevel::Warning, $indicators['aborted_connects_percent']->level);
    }

    public function testNoConnectionAttemptMeansUnknownNotZero(): void
    {
        $indicators = self::indicators(['Connections' => 100, 'Aborted_connects' => 5], ['Connections' => 100, 'Aborted_connects' => 5]);

        self::assertNull($indicators['aborted_connects_percent']->value);
        self::assertSame(HealthLevel::Unknown, $indicators['aborted_connects_percent']->level);
    }

    public function testTemporaryTablesOnDisk(): void
    {
        $indicators = self::indicators(['Created_tmp_tables' => 0, 'Created_tmp_disk_tables' => 0], ['Created_tmp_tables' => 200, 'Created_tmp_disk_tables' => 100]);

        self::assertSame(50.0, $indicators['tmp_disk_tables_percent']->value);
        self::assertSame(HealthLevel::Warning, $indicators['tmp_disk_tables_percent']->level);
    }

    public function testRowLockWaitsWarnOnlyWhenTheyAreLong(): void
    {
        $short = self::indicators(['Innodb_row_lock_waits' => 0, 'Innodb_row_lock_time' => 0], ['Innodb_row_lock_waits' => 10, 'Innodb_row_lock_time' => 500]);
        $long = self::indicators(['Innodb_row_lock_waits' => 0, 'Innodb_row_lock_time' => 0], ['Innodb_row_lock_waits' => 10, 'Innodb_row_lock_time' => 20_000]);

        self::assertSame(1.0, $short['row_lock_waits_per_second']->value);
        self::assertSame(HealthLevel::Ok, $short['row_lock_waits_per_second']->level);
        self::assertSame(HealthLevel::Warning, $long['row_lock_waits_per_second']->level);
    }

    public function testSlowQueriesAndRedoWaits(): void
    {
        $indicators = self::indicators(['Slow_queries' => 0, 'Innodb_log_waits' => 0], ['Slow_queries' => 30, 'Innodb_log_waits' => 2]);

        self::assertSame(3.0, $indicators['slow_queries_per_second']->value);
        self::assertSame(HealthLevel::Warning, $indicators['slow_queries_per_second']->level);
        self::assertSame(2.0, $indicators['redo_log_waits']->value);
        self::assertSame(HealthLevel::Warning, $indicators['redo_log_waits']->level);
    }

    public function testAbsentCountersGiveUnknownIndicatorsNeverAnError(): void
    {
        $indicators = self::indicators([], []);

        self::assertNotEmpty($indicators);
        foreach ($indicators as $indicator) {
            self::assertNull($indicator->value);
            self::assertSame(HealthLevel::Unknown, $indicator->level);
        }
    }

    public function testAResetCounterGivesAnUnknownIndicator(): void
    {
        $indicators = self::indicators(['Questions' => 900], ['Questions' => 10]);

        self::assertNull($indicators['queries_per_second']->value);
    }
}
