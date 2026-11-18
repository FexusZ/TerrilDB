<?php

declare(strict_types=1);

namespace App\Tests\Driver\Redis;

use App\Core\Diagnostics\Availability;
use App\Core\Diagnostics\Replication\ReplicationRole;
use App\Core\Diagnostics\Replication\ThreadState;
use App\Driver\Redis\RedisReplicationReport;
use PHPUnit\Framework\TestCase;

/**
 * Sorties réelles de `INFO replication` (Redis 8) : une source seule, une source avec un réplica, un réplica relié, un réplica
 * dont la source est tombée.
 */
final class RedisReplicationReportTest extends TestCase
{
    private static function info(string $name): string
    {
        return (string) file_get_contents(__DIR__.'/../../Fixtures/replication/'.$name.'.txt');
    }

    public function testAMasterAloneHasNoReplication(): void
    {
        $report = RedisReplicationReport::build(self::info('redis-master-alone'));

        self::assertSame(Availability::Available, $report->status->availability);
        self::assertSame(ReplicationRole::None, $report->role);
        self::assertSame(0, $report->replicaCount);
        self::assertSame([], $report->channels);
        self::assertSame([], $report->extras->replicas?->replicas);
        self::assertSame(1048576, $report->extras->keyValue?->backlogSize);
        self::assertSame(0, $report->extras->keyValue->masterReplOffset);
        self::assertNull($report->extras->keyValue->linkStatus);
        self::assertSame([], $report->alerts());
    }

    public function testAMasterWithAReplicaSeesItWithoutItsAddress(): void
    {
        $report = RedisReplicationReport::build(self::info('redis-master-with-replica'));

        self::assertSame(ReplicationRole::Source, $report->role);
        self::assertSame(1, $report->replicaCount);
        $link = $report->extras->replicas?->replicas[0];
        self::assertSame(1, $link?->rank);
        self::assertSame('online', $link->state);
        self::assertTrue($link->connected);
        self::assertSame(1.0, $link->replayLagSeconds);
        self::assertSame(0, $link->behindBytes);
        self::assertSame([], $report->alerts());
        self::assertStringNotContainsString('172.21', serialize($report));
    }

    public function testAReplicaThatIsLinkedKeepsNeitherHostNorPort(): void
    {
        $report = RedisReplicationReport::build(self::info('redis-replica-up'));

        self::assertSame(ReplicationRole::Replica, $report->role);
        $channel = $report->channels[0];
        self::assertSame(ThreadState::Running, $channel->receiver);
        self::assertSame(ThreadState::Running, $channel->applier);
        self::assertNull($channel->lagSeconds);
        self::assertSame('840', $channel->received);
        self::assertSame('840', $channel->applied);
        self::assertFalse($channel->pendingApply);
        self::assertTrue($report->settings->readOnly);
        self::assertSame('up', $report->extras->keyValue?->linkStatus);
        self::assertSame(7, $report->extras->keyValue->lastIoSecondsAgo);
        self::assertFalse($report->extras->keyValue->syncInProgress);
        self::assertSame([], $report->alerts());
        self::assertStringNotContainsString('redis-primary', serialize($report));
        self::assertStringNotContainsString('6379', serialize($report));
    }

    public function testAReplicaWhoseSourceIsDownIsCritical(): void
    {
        $report = RedisReplicationReport::build(self::info('redis-replica-down'));

        self::assertSame(ThreadState::Stopped, $report->channels[0]->receiver);
        self::assertSame('down', $report->extras->keyValue?->linkStatus);
        self::assertNull($report->extras->keyValue->lastIoSecondsAgo, 'A value of -1 means never.');
        self::assertSame(['receiver_stopped'], array_map(static fn ($a): string => $a->code->value, $report->alerts()));
    }

    public function testAFullSyncInProgressIsAConnectingReceiver(): void
    {
        $info = str_replace(['master_link_status:up', 'master_sync_in_progress:0'], ['master_link_status:down', 'master_sync_in_progress:1'], self::info('redis-replica-up'));

        self::assertSame(ThreadState::Connecting, RedisReplicationReport::build($info)->channels[0]->receiver);
    }

    public function testAWritableReplicaAndAnUnappliedOffsetAreVisible(): void
    {
        $info = str_replace(['slave_read_only:1', 'slave_repl_offset:840'], ['slave_read_only:0', 'slave_repl_offset:800'], self::info('redis-replica-up'));

        $report = RedisReplicationReport::build($info);

        self::assertTrue($report->channels[0]->pendingApply);
        self::assertContains('writable_replica', array_map(static fn ($a): string => $a->code->value, $report->alerts()));
    }

    public function testAReplicaThatFallsBehindOrGoesSilentIsReported(): void
    {
        $info = self::info('redis-master-with-replica');
        $slow = RedisReplicationReport::build(str_replace('lag=1,', 'lag=45,', str_replace('offset=840', 'offset=100', $info)));

        self::assertSame(740, $slow->extras->replicas?->replicas[0]->behindBytes);
        self::assertSame(['replica_lag_high'], array_map(static fn ($a): string => $a->code->value, $slow->alerts()));

        $offline = RedisReplicationReport::build(str_replace('state=online', 'state=wait_bgsave', $info));
        self::assertSame(['replica_not_streaming'], array_map(static fn ($a): string => $a->code->value, $offline->alerts()));

        $silent = RedisReplicationReport::build(str_replace('master_last_io_seconds_ago:7', 'master_last_io_seconds_ago:120', self::info('redis-replica-up')));
        self::assertSame(['link_idle'], array_map(static fn ($a): string => $a->code->value, $silent->alerts()));
    }

    public function testAnOutputWithoutARoleIsRejected(): void
    {
        $this->expectException(\UnexpectedValueException::class);

        RedisReplicationReport::build("# Replication\r\nconnected_slaves:0\r\n");
    }
}
