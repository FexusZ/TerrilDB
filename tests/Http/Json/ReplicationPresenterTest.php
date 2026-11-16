<?php

declare(strict_types=1);

namespace App\Tests\Http\Json;

use App\Core\Diagnostics\DiagnosticStatus;
use App\Core\Diagnostics\Replication\ReplicationChannel;
use App\Core\Diagnostics\Replication\ReplicationError;
use App\Core\Diagnostics\Replication\ReplicationReport;
use App\Core\Diagnostics\Replication\ReplicationRole;
use App\Core\Diagnostics\Replication\ReplicationSettings;
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

    /**
     * @param array<string, mixed> $body
     */
    private static function availabilityOf(array $body): mixed
    {
        self::assertIsArray($body['status']);

        return $body['status']['availability'];
    }
}
