<?php

declare(strict_types=1);

namespace App\Tests\Core\Diagnostics\Replication;

use App\Core\Diagnostics\DiagnosticStatus;
use App\Core\Diagnostics\Replication\AlertSeverity;
use App\Core\Diagnostics\Replication\BinlogState;
use App\Core\Diagnostics\Replication\KeyValueReplication;
use App\Core\Diagnostics\Replication\ReplicaLink;
use App\Core\Diagnostics\Replication\ReplicaLinks;
use App\Core\Diagnostics\Replication\ReplicatedTable;
use App\Core\Diagnostics\Replication\ReplicatedTables;
use App\Core\Diagnostics\Replication\ReplicationAlerts;
use App\Core\Diagnostics\Replication\ReplicationExtras;
use App\Core\Diagnostics\Replication\ReplicationReport;
use App\Core\Diagnostics\Replication\ReplicationRole;
use App\Core\Diagnostics\Replication\ReplicationSettings;
use App\Core\Diagnostics\Replication\ReplicationSlot;
use App\Core\Diagnostics\Replication\ReplicationSlots;
use App\Core\Diagnostics\Replication\SemiSyncMode;
use App\Core\Diagnostics\Replication\SemiSyncState;
use PHPUnit\Framework\TestCase;

final class ReplicationExtrasAlertsTest extends TestCase
{
    private const GIB = 1024 ** 3;

    private static function report(ReplicationExtras $extras, ReplicationRole $role = ReplicationRole::None, ?DiagnosticStatus $status = null): ReplicationReport
    {
        return new ReplicationReport($status ?? DiagnosticStatus::available(), $role, [], new ReplicationSettings(null, null, null, null), null, $extras);
    }

    /**
     * @return list<string>
     */
    private static function codes(ReplicationReport $report): array
    {
        return array_map(static fn ($a): string => $a->code->value.($a->channel ? '@'.$a->channel : ''), ReplicationAlerts::evaluate($report));
    }

    private static function slot(string $name = 's1', bool $active = true, ?int $retained = 0, ?string $walStatus = 'reserved', bool $temporary = false, ?int $safe = null): ReplicationSlot
    {
        return new ReplicationSlot($name, 'physical', $active, $temporary, $retained, $walStatus, $safe);
    }

    private static function slots(ReplicationSlot ...$slots): ReplicationExtras
    {
        return new ReplicationExtras(slots: new ReplicationSlots(DiagnosticStatus::available(), array_values($slots)));
    }

    public function testNoExtrasMeansNoAlertAndAnEmptyReportStaysSilent(): void
    {
        self::assertSame([], self::codes(self::report(new ReplicationExtras())));
        self::assertSame([], self::codes(self::report(self::slots())));
    }

    public function testASlotThatIsActiveAndCloseRaisesNothing(): void
    {
        self::assertSame([], self::codes(self::report(self::slots(self::slot(retained: 1024, walStatus: 'extended')))));
    }

    public function testAnInactiveSlotIsAWarningEvenWithoutAnyReplica(): void
    {
        $alerts = ReplicationAlerts::evaluate(self::report(self::slots(self::slot('orphan', active: false))));

        self::assertSame(['slot_inactive'], array_map(static fn ($a): string => $a->code->value, $alerts));
        self::assertSame('orphan', $alerts[0]->channel);
        self::assertSame(AlertSeverity::Warning, $alerts[0]->severity);
    }

    public function testATemporarySlotNeverRaisesTheInactiveAlert(): void
    {
        self::assertSame([], self::codes(self::report(self::slots(self::slot(active: false, temporary: true)))));
    }

    public function testRetainedWalIsAWarningFromOneGibAndCriticalFromTen(): void
    {
        self::assertSame([], self::codes(self::report(self::slots(self::slot(retained: self::GIB - 1)))));

        $warning = ReplicationAlerts::evaluate(self::report(self::slots(self::slot(retained: self::GIB))));
        self::assertSame('slot_retains_wal', $warning[0]->code->value);
        self::assertSame(AlertSeverity::Warning, $warning[0]->severity);

        $critical = ReplicationAlerts::evaluate(self::report(self::slots(self::slot(retained: 10 * self::GIB))));
        self::assertSame(AlertSeverity::Critical, $critical[0]->severity);
    }

    public function testWalStatusUnreservedWarnsAndLostIsCritical(): void
    {
        $unreserved = ReplicationAlerts::evaluate(self::report(self::slots(self::slot(walStatus: 'unreserved'))));
        self::assertSame(['slot_wal_unreserved', AlertSeverity::Warning], [$unreserved[0]->code->value, $unreserved[0]->severity]);

        $lost = ReplicationAlerts::evaluate(self::report(self::slots(self::slot(walStatus: 'lost'))));
        self::assertSame(['slot_wal_lost', AlertSeverity::Critical], [$lost[0]->code->value, $lost[0]->severity]);
    }

    public function testAnUnreadableSourceStillRaisesNothingFromTheExtras(): void
    {
        self::assertSame([], self::codes(self::report(self::slots(self::slot(active: false)), status: DiagnosticStatus::denied('pg_read_all_stats'))));
    }

    public function testASectionThatIsItselfUnavailableRaisesNothing(): void
    {
        $extras = new ReplicationExtras(slots: new ReplicationSlots(DiagnosticStatus::denied('pg_read_all_stats'), []));

        self::assertSame([], self::codes(self::report($extras)));
    }

    public function testAReplicaThatIsNotStreamingOrLagging(): void
    {
        $links = static fn (ReplicaLink ...$l): ReplicationExtras => new ReplicationExtras(replicas: new ReplicaLinks(DiagnosticStatus::available(), array_values($l)));

        self::assertSame([], self::codes(self::report($links(new ReplicaLink(1, 'streaming', true, 'async', 0.1, 0.2, 0.3, 100)), ReplicationRole::Source)));
        self::assertSame(['replica_not_streaming@1'], self::codes(self::report($links(new ReplicaLink(1, 'catchup', false, 'async', null, null, null, null)), ReplicationRole::Source)));
        self::assertSame(['replica_lag_high@2'], self::codes(self::report($links(new ReplicaLink(2, 'streaming', true, 'sync', 1.0, 2.0, 45.0, null)), ReplicationRole::Source)));
        self::assertSame(['replica_lag_critical@1'], self::codes(self::report($links(new ReplicaLink(1, 'streaming', true, 'async', 0.0, 400.0, 1.0, null)), ReplicationRole::Source)));
    }

    public function testBinlogThatNeverExpiresIsAWarningOnlyWhenBinaryLoggingIsOn(): void
    {
        $binlog = static fn (bool $on, ?int $expire): ReplicationExtras => new ReplicationExtras(binlog: new BinlogState(DiagnosticStatus::available(), $on, 3, 1000, $expire, null, null));

        self::assertSame(['binlog_no_expiry'], self::codes(self::report($binlog(true, 0))));
        self::assertSame([], self::codes(self::report($binlog(true, 2592000))));
        self::assertSame([], self::codes(self::report($binlog(true, null))));
        self::assertSame([], self::codes(self::report($binlog(false, 0))));
    }

    public function testSemiSyncThatFellBackToAsyncIsAWarningAndNotLoadedIsSilent(): void
    {
        $semi = static fn (SemiSyncMode $source, SemiSyncMode $replica = SemiSyncMode::NotLoaded): ReplicationExtras => new ReplicationExtras(semiSync: new SemiSyncState(DiagnosticStatus::available(), $source, $replica, 0));

        self::assertSame([], self::codes(self::report($semi(SemiSyncMode::NotLoaded))));
        self::assertSame([], self::codes(self::report($semi(SemiSyncMode::Off))));
        self::assertSame([], self::codes(self::report($semi(SemiSyncMode::Active))));
        self::assertSame(['semisync_fallback'], self::codes(self::report($semi(SemiSyncMode::FallenBack))));
        self::assertSame(['semisync_fallback'], self::codes(self::report($semi(SemiSyncMode::Off, SemiSyncMode::FallenBack))));
    }

    public function testReplicatedTablesAreJudgedOneByOne(): void
    {
        $table = static fn (bool $readonly = false, bool $expired = false, int $delay = 0, int $queue = 0, int $lost = 0): ReplicationExtras => new ReplicationExtras(tables: new ReplicatedTables(DiagnosticStatus::available(), [new ReplicatedTable('shop', 't', $readonly, $expired, $delay, $queue, 0, $lost)], false));

        self::assertSame([], self::codes(self::report($table(), ReplicationRole::Peer)));
        self::assertSame(['table_readonly@shop.t'], self::codes(self::report($table(readonly: true), ReplicationRole::Peer)));
        self::assertSame(['table_readonly@shop.t', 'table_session_expired@shop.t'], self::codes(self::report($table(readonly: true, expired: true), ReplicationRole::Peer)));
        self::assertSame(['table_lag_high@shop.t'], self::codes(self::report($table(delay: 30), ReplicationRole::Peer)));
        self::assertSame(['table_lag_critical@shop.t'], self::codes(self::report($table(delay: 300), ReplicationRole::Peer)));
        self::assertSame(['table_queue_high@shop.t'], self::codes(self::report($table(queue: 100), ReplicationRole::Peer)));
        self::assertSame(['table_lost_parts@shop.t'], self::codes(self::report($table(lost: 1), ReplicationRole::Peer)));
    }

    public function testAKeyValueLinkThatIsIdleIsJudgedByItsSilence(): void
    {
        $link = static fn (?int $idle): ReplicationExtras => new ReplicationExtras(keyValue: new KeyValueReplication('up', $idle, false, 1048576, 0));

        self::assertSame([], self::codes(self::report($link(2), ReplicationRole::Replica)));
        self::assertSame(['link_idle'], self::codes(self::report($link(30), ReplicationRole::Replica)));
        self::assertSame(['link_idle'], self::codes(self::report($link(400), ReplicationRole::Replica)));
        self::assertSame([], self::codes(self::report($link(null), ReplicationRole::Replica)));
        self::assertSame(AlertSeverity::Critical, ReplicationAlerts::evaluate(self::report($link(400), ReplicationRole::Replica))[0]->severity);
    }

    public function testTheExtrasDoNotChangeTheExistingContract(): void
    {
        $report = new ReplicationReport(DiagnosticStatus::available(), ReplicationRole::None, [], new ReplicationSettings(null, null, null, null), null);

        self::assertNull($report->extras->slots);
        self::assertNull($report->extras->binlog);
        self::assertSame([], $report->alerts());
        self::assertNull(ReplicationReport::unreadable(DiagnosticStatus::denied('X'))->extras->tables);
    }
}
