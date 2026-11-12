<?php

declare(strict_types=1);

namespace App\Tests\Driver\PostgreSql;

use App\Core\Diagnostics\Replication\ReplicationRole;
use App\Core\Diagnostics\Replication\ThreadState;
use App\Driver\PostgreSql\PostgreSqlReplicationReport;
use PHPUnit\Framework\TestCase;

/**
 * Lignes réelles du relevé de PostgreSQL 18 sur une paire primaire / réplica en streaming.
 */
final class PostgreSqlReplicationReportTest extends TestCase
{
    /**
     * @return array<string, mixed>
     */
    private static function row(string $name): array
    {
        /** @var list<array<string, mixed>> $rows */
        $rows = json_decode((string) file_get_contents(__DIR__.'/../../Fixtures/replication/'.$name.'.json'), true, flags: \JSON_THROW_ON_ERROR);

        return $rows[0];
    }

    public function testAStreamingReplicaThatIsCaughtUp(): void
    {
        $report = PostgreSqlReplicationReport::build(self::row('pg-replica-healthy'));

        self::assertSame(ReplicationRole::Replica, $report->role);
        self::assertCount(1, $report->channels);
        $channel = $report->channels[0];
        self::assertSame(ThreadState::Running, $channel->receiver);
        self::assertSame(ThreadState::Running, $channel->applier);
        self::assertSame(0, $channel->lagSeconds);
        self::assertSame('0/3000060', $channel->received);
        self::assertSame('0/3000060', $channel->applied);
        self::assertFalse($channel->pendingApply);
        self::assertTrue($report->settings->readOnly);
        self::assertSame([], $report->alerts());
    }

    public function testAPausedReplayIsAStoppedApplierWithReceivedWalNotApplied(): void
    {
        $report = PostgreSqlReplicationReport::build(self::row('pg-replica-paused'));

        $channel = $report->channels[0];
        self::assertSame(ThreadState::Running, $channel->receiver);
        self::assertSame(ThreadState::Stopped, $channel->applier);
        self::assertTrue($channel->pendingApply);
        self::assertSame(['applier_stopped'], array_map(static fn ($a): string => $a->code->value, $report->alerts()));
    }

    public function testNoWalReceiverRowMeansTheReceiverIsNotConnected(): void
    {
        $channel = PostgreSqlReplicationReport::build(self::row('pg-replica-disconnected'))->channels[0];

        self::assertSame(ThreadState::Stopped, $channel->receiver);
    }

    public function testAPrimaryWithAReplicaIsASource(): void
    {
        $report = PostgreSqlReplicationReport::build(self::row('pg-source'));

        self::assertSame(ReplicationRole::Source, $report->role);
        self::assertSame(1, $report->replicaCount);
        self::assertSame([], $report->channels);
        self::assertSame([], $report->alerts());
    }

    public function testAPrimaryWithoutReplicasHasNoReplication(): void
    {
        $row = self::row('pg-source');
        $row['replica_count'] = 0;

        $report = PostgreSqlReplicationReport::build($row);

        self::assertSame(ReplicationRole::None, $report->role);
        self::assertSame(0, $report->replicaCount);
        self::assertSame([], $report->alerts());
    }

    public function testStartingStatesAreConnecting(): void
    {
        foreach (['starting', 'startup', 'waiting', 'restarting'] as $status) {
            $row = self::row('pg-replica-healthy');
            $row['receiver_status'] = $status;
            self::assertSame(ThreadState::Connecting, PostgreSqlReplicationReport::build($row)->channels[0]->receiver, $status);
        }
        foreach (['streaming', 'catchup'] as $status) {
            $row = self::row('pg-replica-healthy');
            $row['receiver_status'] = $status;
            self::assertSame(ThreadState::Running, PostgreSqlReplicationReport::build($row)->channels[0]->receiver, $status);
        }
        $row = self::row('pg-replica-healthy');
        $row['receiver_status'] = 'stopping';
        self::assertSame(ThreadState::Stopped, PostgreSqlReplicationReport::build($row)->channels[0]->receiver);
    }

    public function testALagAboveTheThresholdRaisesAnAlert(): void
    {
        $row = self::row('pg-replica-paused');
        $row['paused'] = false;
        $row['lag_seconds'] = 45;

        self::assertSame(['lag_high'], array_map(static fn ($a): string => $a->code->value, PostgreSqlReplicationReport::build($row)->alerts()));
    }
}
