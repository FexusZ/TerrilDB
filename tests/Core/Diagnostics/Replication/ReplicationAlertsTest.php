<?php

declare(strict_types=1);

namespace App\Tests\Core\Diagnostics\Replication;

use App\Core\Diagnostics\DiagnosticStatus;
use App\Core\Diagnostics\Replication\AlertSeverity;
use App\Core\Diagnostics\Replication\ReplicationAlertCode;
use App\Core\Diagnostics\Replication\ReplicationAlerts;
use App\Core\Diagnostics\Replication\ReplicationChannel;
use App\Core\Diagnostics\Replication\ReplicationError;
use App\Core\Diagnostics\Replication\ReplicationReport;
use App\Core\Diagnostics\Replication\ReplicationRole;
use App\Core\Diagnostics\Replication\ReplicationSettings;
use App\Core\Diagnostics\Replication\ThreadState;
use PHPUnit\Framework\TestCase;

final class ReplicationAlertsTest extends TestCase
{
    private static function channel(ThreadState $receiver = ThreadState::Running, ThreadState $applier = ThreadState::Running, ?int $lag = 0, ?ReplicationError $receiverError = null, ?ReplicationError $applierError = null): ReplicationChannel
    {
        return new ReplicationChannel('', $receiver, $applier, $lag, null, null, null, $receiverError, $applierError);
    }

    private static function replica(ReplicationChannel $channel, ReplicationSettings $settings = new ReplicationSettings(true, true, 'ROW', true)): ReplicationReport
    {
        return new ReplicationReport(DiagnosticStatus::available(), ReplicationRole::Replica, [$channel], $settings, 0);
    }

    /**
     * @return list<string>
     */
    private static function codes(ReplicationReport $report): array
    {
        return array_map(static fn ($a): string => $a->code->value, ReplicationAlerts::evaluate($report));
    }

    public function testAHealthyReplicaRaisesNothing(): void
    {
        self::assertSame([], self::codes(self::replica(self::channel())));
    }

    public function testAServerWithoutReplicationNeverRaisesAnything(): void
    {
        $report = new ReplicationReport(DiagnosticStatus::available(), ReplicationRole::None, [], new ReplicationSettings(false, false, 'MIXED', false), null);

        self::assertSame([], ReplicationAlerts::evaluate($report));
    }

    public function testAnUnreadableSourceRaisesNothing(): void
    {
        $report = new ReplicationReport(DiagnosticStatus::denied('REPLICATION CLIENT'), ReplicationRole::None, [], new ReplicationSettings(null, null, null, null), null);

        self::assertSame([], ReplicationAlerts::evaluate($report));
    }

    public function testAStoppedThreadIsCritical(): void
    {
        $alerts = ReplicationAlerts::evaluate(self::replica(self::channel(ThreadState::Stopped, ThreadState::Stopped, null)));

        self::assertSame(['receiver_stopped', 'applier_stopped'], array_map(static fn ($a): string => $a->code->value, $alerts));
        self::assertSame(AlertSeverity::Critical, $alerts[0]->severity);
    }

    public function testAReceiverThatIsReconnectingIsAWarning(): void
    {
        $alerts = ReplicationAlerts::evaluate(self::replica(self::channel(ThreadState::Connecting, lag: null)));

        self::assertSame([ReplicationAlertCode::ReceiverConnecting, ReplicationAlertCode::LagUnknown], array_map(static fn ($a): ReplicationAlertCode => $a->code, $alerts));
        self::assertSame(AlertSeverity::Warning, $alerts[0]->severity);
    }

    public function testErrorsAreCriticalEvenWhenThreadsRun(): void
    {
        $report = self::replica(self::channel(receiverError: ReplicationError::from(2003, 'cannot connect'), applierError: ReplicationError::from(1062, 'dup')));

        self::assertSame(['receiver_error', 'applier_error'], self::codes($report));
    }

    public function testLagThresholds(): void
    {
        self::assertSame([], self::codes(self::replica(self::channel(lag: ReplicationAlerts::LAG_WARNING_SECONDS - 1))));
        self::assertSame(['lag_high'], self::codes(self::replica(self::channel(lag: ReplicationAlerts::LAG_WARNING_SECONDS))));
        $critical = ReplicationAlerts::evaluate(self::replica(self::channel(lag: ReplicationAlerts::LAG_CRITICAL_SECONDS)));
        self::assertSame(ReplicationAlertCode::LagCritical, $critical[0]->code);
        self::assertSame(AlertSeverity::Critical, $critical[0]->severity);
    }

    public function testAnUnknownLagIsOnlyReportedWhileTheApplierRuns(): void
    {
        self::assertSame(['lag_unknown'], self::codes(self::replica(self::channel(lag: null))));
        self::assertSame(['applier_stopped'], self::codes(self::replica(self::channel(applier: ThreadState::Stopped, lag: null))));
    }

    public function testAWritableReplicaIsReported(): void
    {
        self::assertSame(['writable_replica'], self::codes(self::replica(self::channel(), new ReplicationSettings(true, false, 'ROW', true))));
        // Sans super_read_only (MariaDB, PostgreSQL), c'est read_only qui compte.
        self::assertSame(['writable_replica'], self::codes(self::replica(self::channel(), new ReplicationSettings(false, null, 'ROW', true))));
        self::assertSame([], self::codes(self::replica(self::channel(), new ReplicationSettings(true, null, 'ROW', true))));
        self::assertSame([], self::codes(self::replica(self::channel(), new ReplicationSettings(null, null, 'ROW', true))));
    }

    public function testBinlogFormatAndGtid(): void
    {
        self::assertSame(['binlog_format_not_row', 'gtid_disabled'], self::codes(self::replica(self::channel(), new ReplicationSettings(true, true, 'mixed', false))));
        self::assertSame([], self::codes(self::replica(self::channel(), new ReplicationSettings(true, true, 'row', true))));
    }

    public function testASourceIsCheckedForFormatAndGtidButNotForWritability(): void
    {
        $report = new ReplicationReport(DiagnosticStatus::available(), ReplicationRole::Source, [], new ReplicationSettings(false, false, 'STATEMENT', false), 2);

        self::assertSame(['binlog_format_not_row', 'gtid_disabled'], self::codes($report));
    }

    public function testEachAlertNamesItsChannel(): void
    {
        $channel = new ReplicationChannel('east', ThreadState::Stopped, ThreadState::Running, 0, null, null, null, null, null);

        self::assertSame('east', ReplicationAlerts::evaluate(self::replica($channel))[0]->channel);
    }
}
