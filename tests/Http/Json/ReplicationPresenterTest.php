<?php

declare(strict_types=1);

namespace App\Tests\Http\Json;

use App\Core\Diagnostics\DiagnosticStatus;
use App\Core\Diagnostics\Replication\BinlogState;
use App\Core\Diagnostics\Replication\KeyValueReplication;
use App\Core\Diagnostics\Replication\ReplicaLink;
use App\Core\Diagnostics\Replication\ReplicaLinks;
use App\Core\Diagnostics\Replication\ReplicatedTable;
use App\Core\Diagnostics\Replication\ReplicatedTables;
use App\Core\Diagnostics\Replication\ReplicationChannel;
use App\Core\Diagnostics\Replication\ReplicationError;
use App\Core\Diagnostics\Replication\ReplicationExtras;
use App\Core\Diagnostics\Replication\ReplicationReport;
use App\Core\Diagnostics\Replication\ReplicationRole;
use App\Core\Diagnostics\Replication\ReplicationSettings;
use App\Core\Diagnostics\Replication\ReplicationSlot;
use App\Core\Diagnostics\Replication\ReplicationSlots;
use App\Core\Diagnostics\Replication\SemiSyncMode;
use App\Core\Diagnostics\Replication\SemiSyncState;
use App\Core\Diagnostics\Replication\ThreadState;
use App\Http\Json\DiagnosticsPresenter;
use PHPUnit\Framework\TestCase;

final class ReplicationPresenterTest extends TestCase
{
    public function testAReplicaWithItsAlerts(): void
    {
        $channel = new ReplicationChannel('', ThreadState::Running, ThreadState::Stopped, null, 'u:1-11', 'u:1-10', true, null, ReplicationError::from(1062, 'Duplicate entry'));
        $report = new ReplicationReport(DiagnosticStatus::available(), ReplicationRole::Replica, [$channel], new ReplicationSettings(true, false, 'ROW', true), 0);

        $body = DiagnosticsPresenter::replication($report);

        self::assertSame(['status', 'role', 'replicaCount', 'settings', 'channels', 'alerts', 'thresholds'], array_keys($body));
        self::assertSame('replica', $body['role']);
        self::assertSame(['readOnly' => true, 'superReadOnly' => false, 'binlogFormat' => 'ROW', 'gtid' => true], $body['settings']);
        self::assertSame(
            [['name' => '', 'receiver' => 'running', 'applier' => 'stopped', 'lagSeconds' => null, 'received' => 'u:1-11', 'applied' => 'u:1-10', 'pendingApply' => true, 'receiverError' => null, 'applierError' => ['code' => 1062, 'message' => 'Duplicate entry']]],
            $body['channels'],
        );
        self::assertSame(
            [['code' => 'applier_stopped', 'severity' => 'critical', 'channel' => ''], ['code' => 'applier_error', 'severity' => 'critical', 'channel' => ''], ['code' => 'writable_replica', 'severity' => 'warning', 'channel' => '']],
            $body['alerts'],
        );
        self::assertSame(['warning' => 30, 'critical' => 300], $body['thresholds']);
    }

    public function testNothingIsHiddenWhenMaskingIsOff(): void
    {
        $channel = new ReplicationChannel('', ThreadState::Running, ThreadState::Stopped, null, null, null, false, null, ReplicationError::from(1045, "Access denied for user 'repl'@'10.0.0.5', SOURCE_PASSWORD='hunter2'"));
        $report = new ReplicationReport(DiagnosticStatus::available(), ReplicationRole::Replica, [$channel], new ReplicationSettings(null, null, null, null), 0);

        $masked = DiagnosticsPresenter::replication($report);
        $open = DiagnosticsPresenter::replication($report, false);

        self::assertStringNotContainsString('10.0.0.5', json_encode($masked, \JSON_THROW_ON_ERROR));
        self::assertStringNotContainsString('hunter2', json_encode($masked, \JSON_THROW_ON_ERROR));
        self::assertStringContainsString('10.0.0.5', json_encode($open, \JSON_THROW_ON_ERROR));
        self::assertStringContainsString('hunter2', json_encode($open, \JSON_THROW_ON_ERROR));
    }

    public function testNoReplicationIsAStateWithoutAlert(): void
    {
        $body = DiagnosticsPresenter::replication(new ReplicationReport(DiagnosticStatus::available(), ReplicationRole::None, [], new ReplicationSettings(null, null, null, null), 0));

        self::assertSame('none', $body['role']);
        self::assertSame([], $body['channels']);
        self::assertSame([], $body['alerts']);
        self::assertSame('available', self::availabilityOf($body));
    }

    public function testAnUnreadableSourceSaysWhy(): void
    {
        $body = DiagnosticsPresenter::replication(ReplicationReport::unreadable(DiagnosticStatus::denied('REPLICATION CLIENT')));

        self::assertSame(['availability' => 'permission_denied', 'reason' => 'insufficient_privilege', 'privilege' => 'REPLICATION CLIENT'], $body['status']);
        self::assertSame([], $body['alerts']);
    }

    public function testWithoutExtrasTheBodyKeepsExactlyItsHistoricalShape(): void
    {
        $body = DiagnosticsPresenter::replication(new ReplicationReport(DiagnosticStatus::available(), ReplicationRole::None, [], new ReplicationSettings(null, null, null, null), 0));

        self::assertSame(['status', 'role', 'replicaCount', 'settings', 'channels', 'alerts', 'thresholds'], array_keys($body));
    }

    public function testPostgreSqlSlotsAndReplicasAreListedWithTheirLimits(): void
    {
        $extras = new ReplicationExtras(
            slots: new ReplicationSlots(DiagnosticStatus::available(), [new ReplicationSlot('orphan', 'physical', false, false, 50331704, 'reserved', null)]),
            replicas: new ReplicaLinks(DiagnosticStatus::available(), [new ReplicaLink(1, 'streaming', true, 'async', 0.001, 0.002, 0.003, 0)]),
        );

        $body = DiagnosticsPresenter::replication(new ReplicationReport(DiagnosticStatus::available(), ReplicationRole::Source, [], new ReplicationSettings(null, null, null, null), 1, $extras));

        self::assertSame(
            ['status' => ['availability' => 'available', 'reason' => null, 'privilege' => null], 'slots' => [['name' => 'orphan', 'type' => 'physical', 'active' => false, 'temporary' => false, 'retainedBytes' => 50331704, 'walStatus' => 'reserved', 'safeWalSize' => null]]],
            $body['slots'],
        );
        self::assertSame(
            ['status' => ['availability' => 'available', 'reason' => null, 'privilege' => null], 'replicas' => [['rank' => 1, 'state' => 'streaming', 'connected' => true, 'syncState' => 'async', 'writeLagSeconds' => 0.001, 'flushLagSeconds' => 0.002, 'replayLagSeconds' => 0.003, 'behindBytes' => 0]]],
            $body['replicas'],
        );
        self::assertSame([['code' => 'slot_inactive', 'severity' => 'warning', 'channel' => 'orphan']], $body['alerts']);
        self::assertSame(['slotRetainedWarningBytes' => 1073741824, 'slotRetainedCriticalBytes' => 10737418240, 'tableQueueWarning' => 100], $body['limits']);
        self::assertArrayNotHasKey('binlog', $body);
        self::assertArrayNotHasKey('replicatedTables', $body);
    }

    public function testBinlogAndSemiSyncOfMySql(): void
    {
        $extras = new ReplicationExtras(
            binlog: new BinlogState(DiagnosticStatus::available(), true, 2, 1000, 2592000, 'u:1-5', null),
            semiSync: new SemiSyncState(DiagnosticStatus::disabled('semi_sync_not_loaded'), SemiSyncMode::NotLoaded, SemiSyncMode::NotLoaded, null),
        );

        $body = DiagnosticsPresenter::replication(new ReplicationReport(DiagnosticStatus::available(), ReplicationRole::None, [], new ReplicationSettings(null, null, null, null), 0, $extras));

        self::assertSame(['status' => ['availability' => 'available', 'reason' => null, 'privilege' => null], 'enabled' => true, 'fileCount' => 2, 'totalBytes' => 1000, 'expireSeconds' => 2592000, 'gtidExecuted' => 'u:1-5', 'gtidPurged' => null], $body['binlog']);
        self::assertSame(['status' => ['availability' => 'disabled', 'reason' => 'semi_sync_not_loaded', 'privilege' => null], 'source' => 'not_loaded', 'replica' => 'not_loaded', 'clients' => null], $body['semiSync']);
    }

    public function testClickHouseTablesAndRedisLink(): void
    {
        $extras = new ReplicationExtras(
            tables: new ReplicatedTables(DiagnosticStatus::available(), [new ReplicatedTable('d', 'rt', false, false, 3, 4, 5, 0)], true),
            keyValue: new KeyValueReplication('up', 7, false, 1048576, 840),
        );

        $body = DiagnosticsPresenter::replication(new ReplicationReport(DiagnosticStatus::available(), ReplicationRole::Peer, [], new ReplicationSettings(null, null, null, null), null, $extras));

        self::assertSame('peer', $body['role']);
        self::assertSame(['status' => ['availability' => 'available', 'reason' => null, 'privilege' => null], 'tables' => [['database' => 'd', 'table' => 'rt', 'readonly' => false, 'sessionExpired' => false, 'absoluteDelaySeconds' => 3, 'queueSize' => 4, 'insertsInQueue' => 5, 'lostParts' => 0]], 'truncated' => true], $body['replicatedTables']);
        self::assertSame(['linkStatus' => 'up', 'lastIoSecondsAgo' => 7, 'syncInProgress' => false, 'backlogSize' => 1048576, 'masterReplOffset' => 840], $body['keyValue']);
    }

    /**
     * @param array<string, mixed> $body
     */
    private static function availabilityOf(array $body): mixed
    {
        self::assertIsArray($body['status']);

        return $body['status']['availability'];
    }
}
