<?php

declare(strict_types=1);

namespace App\Tests\Driver\MySql;

use App\Core\Connection\Engine;
use App\Core\Diagnostics\Availability;
use App\Core\Diagnostics\Replication\SemiSyncMode;
use App\Driver\MySql\MySqlReplicationSections;
use PHPUnit\Framework\TestCase;

/**
 * Relevés réels : MySQL 8.4 (GTID éteints) et 26 (GTID allumés), MariaDB 11.8 sans journal binaire et avec journal binaire sans
 * expiration, MySQL 8.4 avec le greffon semi-synchrone chargé (actif, puis repassé en asynchrone après un délai dépassé).
 */
final class MySqlReplicationSectionsTest extends TestCase
{
    /**
     * @return array{variables: array<string, string>, status: array<string, string>, logs: list<array<string, string>>|null}
     */
    private static function capture(string $name): array
    {
        /** @var array{variables: array<string, string>, status: array<string, string>, logs: list<array<string, string>>|null} $data */
        $data = json_decode((string) file_get_contents(__DIR__.'/../../Fixtures/replication/'.$name.'.json'), true, flags: \JSON_THROW_ON_ERROR);

        return $data;
    }

    public function testMySqlBinaryLogsWithGtidOff(): void
    {
        $c = self::capture('my-binlog-gtid-off');

        $binlog = MySqlReplicationSections::binlog(Engine::MySql, $c['variables'], $c['logs']);

        self::assertSame(Availability::Available, $binlog->status->availability);
        self::assertTrue($binlog->enabled);
        self::assertSame(2, $binlog->fileCount);
        self::assertSame(3267761 + 597578115, $binlog->totalBytes);
        self::assertSame(2592000, $binlog->expireSeconds);
        self::assertNull($binlog->gtidExecuted);
        self::assertNull($binlog->gtidPurged);
    }

    public function testMySqlGtidSetsAreKept(): void
    {
        $c = self::capture('my-binlog-gtid-on');

        $binlog = MySqlReplicationSections::binlog(Engine::MySql, $c['variables'], $c['logs']);

        self::assertSame('b84bf3d2-e053-11f1-b4d8-ce4699dd6dc7:1-166347', $binlog->gtidExecuted);
        self::assertNull($binlog->gtidPurged);
    }

    public function testALongGtidSetIsNormalizedAndBounded(): void
    {
        $long = implode(",\n", array_map(static fn (int $i): string => \sprintf('aaaaaaaa-0000-0000-0000-%012d:1-%d', $i, $i * 1000), range(1, 40)));

        $binlog = MySqlReplicationSections::binlog(Engine::MySql, ['log_bin' => 'ON', 'gtid_executed' => $long, 'binlog_expire_logs_seconds' => '60'], []);

        self::assertStringNotContainsString("\n", (string) $binlog->gtidExecuted);
        self::assertLessThanOrEqual(501, mb_strlen((string) $binlog->gtidExecuted));
        self::assertStringEndsWith('…', (string) $binlog->gtidExecuted);
    }

    public function testBinaryLoggingOffIsAnExplainedStateNotAnError(): void
    {
        $c = self::capture('ma-binlog-off');

        $binlog = MySqlReplicationSections::binlog(Engine::MariaDb, $c['variables'], $c['logs']);

        self::assertSame(Availability::Disabled, $binlog->status->availability);
        self::assertSame('binary_log_off', $binlog->status->reason);
        self::assertFalse($binlog->enabled);
        self::assertNull($binlog->fileCount);
    }

    public function testMariaDbBinaryLogsWithoutExpirationAndItsGtidPosition(): void
    {
        $c = self::capture('ma-binlog-on-no-expiry');

        $binlog = MySqlReplicationSections::binlog(Engine::MariaDb, $c['variables'], $c['logs']);

        self::assertTrue($binlog->enabled);
        self::assertSame(3, $binlog->fileCount);
        self::assertSame(0, $binlog->expireSeconds);
        self::assertSame('0-1-4', $binlog->gtidExecuted);
        self::assertNull($binlog->gtidPurged);
    }

    public function testExpirationFallsBackToDaysThenIsUnknownWhenNeitherExists(): void
    {
        $days = MySqlReplicationSections::binlog(Engine::MySql, ['log_bin' => 'ON', 'expire_logs_days' => '7.000000'], []);
        self::assertSame(7 * 86400, $days->expireSeconds);

        self::assertNull(MySqlReplicationSections::binlog(Engine::MySql, ['log_bin' => 'ON'], [])->expireSeconds);

        $manual = MySqlReplicationSections::binlog(Engine::MySql, ['log_bin' => 'ON', 'binlog_expire_logs_seconds' => '2592000', 'binlog_expire_logs_auto_purge' => 'OFF'], []);
        self::assertSame(0, $manual->expireSeconds, 'Automatic purge off means nothing expires.');
    }

    public function testASemiSyncPluginThatIsNotLoadedIsSaidSo(): void
    {
        $c = self::capture('my-binlog-gtid-on');

        $semi = MySqlReplicationSections::semiSync($c['variables'], $c['status']);

        self::assertSame(Availability::Disabled, $semi->status->availability);
        self::assertSame('semi_sync_not_loaded', $semi->status->reason);
        self::assertSame(SemiSyncMode::NotLoaded, $semi->source);
        self::assertSame(SemiSyncMode::NotLoaded, $semi->replica);
    }

    public function testMySqlSemiSyncIsActiveThenFallsBackToAsync(): void
    {
        $active = self::capture('my-semisync-active');
        $semi = MySqlReplicationSections::semiSync($active['variables'], $active['status']);
        self::assertSame(Availability::Available, $semi->status->availability);
        self::assertSame(SemiSyncMode::Active, $semi->source);
        self::assertSame(SemiSyncMode::Off, $semi->replica);
        self::assertSame(0, $semi->clients);

        $fallback = self::capture('my-semisync-fallback');
        self::assertSame(SemiSyncMode::FallenBack, MySqlReplicationSections::semiSync($fallback['variables'], $fallback['status'])->source);
    }

    public function testMariaDbSemiSyncKeepsItsOwnNamesAndIsOff(): void
    {
        $c = self::capture('ma-binlog-off');

        $semi = MySqlReplicationSections::semiSync($c['variables'], $c['status']);

        self::assertSame(Availability::Available, $semi->status->availability);
        self::assertSame(SemiSyncMode::Off, $semi->source);
        self::assertSame(SemiSyncMode::Off, $semi->replica);
        self::assertSame(0, $semi->clients);
    }
}
