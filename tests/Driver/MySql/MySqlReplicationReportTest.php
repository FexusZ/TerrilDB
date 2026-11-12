<?php

declare(strict_types=1);

namespace App\Tests\Driver\MySql;

use App\Core\Connection\Engine;
use App\Core\Diagnostics\DiagnosticStatus;
use App\Core\Diagnostics\Replication\ReplicationError;
use App\Core\Diagnostics\Replication\ReplicationRole;
use App\Core\Diagnostics\Replication\ThreadState;
use App\Driver\MySql\MySqlReplicationReport;
use PHPUnit\Framework\TestCase;

/**
 * Sorties réelles, relevées sur une paire source / réplica MySQL 8.4 et MariaDB 11.8 (hôtes et adresses remplacés).
 */
final class MySqlReplicationReportTest extends TestCase
{
    /**
     * @return list<array<string, mixed>>
     */
    private static function rows(string $name): array
    {
        /** @var list<array<string, mixed>> $rows */
        $rows = json_decode((string) file_get_contents(__DIR__.'/../../Fixtures/replication/'.$name.'.json'), true, flags: \JSON_THROW_ON_ERROR);

        return $rows;
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function text(array $row, string $key): string
    {
        $value = $row[$key] ?? null;
        self::assertIsScalar($value);

        return (string) $value;
    }

    /**
     * @return array<string, string>
     */
    private static function variables(string $name): array
    {
        $variables = [];
        foreach (self::rows($name) as $row) {
            $variables[self::text($row, 'Variable_name')] = self::text($row, 'Value');
        }

        return $variables;
    }

    public function testAHealthyMySqlReplica(): void
    {
        $report = MySqlReplicationReport::build(Engine::MySql, self::rows('my-healthy'), self::variables('my-variables') + ['super_read_only' => 'ON'], 0, ['' => ''], []);

        self::assertTrue($report->status->isAvailable());
        self::assertSame(ReplicationRole::Replica, $report->role);
        self::assertCount(1, $report->channels);
        $channel = $report->channels[0];
        self::assertSame('', $channel->name);
        self::assertSame(ThreadState::Running, $channel->receiver);
        self::assertSame(ThreadState::Running, $channel->applier);
        self::assertSame(0, $channel->lagSeconds);
        self::assertSame('ec71da98-deed-11f1-9c92-ee27787b0253:1-10', $channel->received);
        self::assertSame('ec71da98-deed-11f1-9c92-ee27787b0253:1-10, ec8eb605-deed-11f1-8205-82acd344a367:1-5', $channel->applied);
        self::assertFalse($channel->pendingApply);
        self::assertNull($channel->receiverError);
        self::assertNull($channel->applierError);
        self::assertSame('ROW', $report->settings->binlogFormat);
        self::assertTrue($report->settings->gtid);
        self::assertSame(0, $report->replicaCount);
    }

    public function testTheSourceHostAndAccountNeverReachTheReport(): void
    {
        $report = MySqlReplicationReport::build(Engine::MySql, self::rows('my-healthy'), [], null, [], []);

        self::assertStringNotContainsString('db-source', serialize($report));
        self::assertStringNotContainsString('repl@', serialize($report));
        self::assertStringNotContainsString('3306', serialize($report));
    }

    public function testReceivedButNotYetAppliedWhileTheApplierIsStopped(): void
    {
        $report = MySqlReplicationReport::build(Engine::MySql, self::rows('my-applier-stopped'), [], null, ['' => 'ec71da98-deed-11f1-9c92-ee27787b0253:11'], []);

        $channel = $report->channels[0];
        self::assertSame(ThreadState::Running, $channel->receiver);
        self::assertSame(ThreadState::Stopped, $channel->applier);
        self::assertNull($channel->lagSeconds);
        self::assertTrue($channel->pendingApply);
    }

    public function testAStoppedReceiver(): void
    {
        $channel = MySqlReplicationReport::build(Engine::MySql, self::rows('my-receiver-stopped'), [], null, ['' => ''], [])->channels[0];

        self::assertSame(ThreadState::Stopped, $channel->receiver);
        self::assertSame(ThreadState::Running, $channel->applier);
        self::assertFalse($channel->pendingApply);
    }

    public function testAReceiverThatCannotConnectKeepsANeutralisedError(): void
    {
        $channel = MySqlReplicationReport::build(Engine::MySql, self::rows('my-receiver-error'), [], null, [], [])->channels[0];

        self::assertSame(ThreadState::Connecting, $channel->receiver);
        self::assertNotNull($channel->receiverError);
        self::assertSame(1045, $channel->receiverError->code);
        self::assertStringContainsString('Access denied', $channel->receiverError->message);
        self::assertStringNotContainsString('db-source', $channel->receiverError->message);
        self::assertStringNotContainsString('10.1.2.3', $channel->receiverError->message);
        self::assertStringNotContainsString('repl@', $channel->receiverError->message);
    }

    public function testTheWorkerErrorExplainsAnApplierFailureBetter(): void
    {
        $worker = ReplicationError::from(1062, self::text(self::rows('my-worker-error')[0], 'LAST_ERROR_MESSAGE'));
        self::assertNotNull($worker);

        $without = MySqlReplicationReport::build(Engine::MySql, self::rows('my-applier-error'), [], null, [], [])->channels[0];
        $with = MySqlReplicationReport::build(Engine::MySql, self::rows('my-applier-error'), [], null, [], ['' => $worker])->channels[0];

        self::assertNotNull($without->applierError);
        self::assertSame(1062, $without->applierError->code);
        self::assertStringContainsString('Coordinator stopped', $without->applierError->message);
        self::assertNotNull($with->applierError);
        self::assertStringContainsString('Duplicate entry', $with->applierError->message);
        self::assertStringContainsString('t.a', $with->applierError->message);
    }

    public function testAHealthyMariaDbReplicaUsesItsOwnColumnNames(): void
    {
        $report = MySqlReplicationReport::build(Engine::MariaDb, self::rows('ma-healthy'), self::variables('ma-variables'), 0, [], []);

        self::assertSame(ReplicationRole::Replica, $report->role);
        $channel = $report->channels[0];
        self::assertSame(ThreadState::Running, $channel->receiver);
        self::assertSame(ThreadState::Running, $channel->applier);
        self::assertSame(0, $channel->lagSeconds);
        self::assertSame('0-1-5', $channel->received);
        self::assertSame('0-1-5', $channel->applied);
        self::assertFalse($channel->pendingApply);
        self::assertTrue($report->settings->gtid);
        self::assertFalse($report->settings->readOnly);
        self::assertNull($report->settings->superReadOnly);
        self::assertSame('MIXED', $report->settings->binlogFormat);
    }

    public function testMariaDbReceivedAheadOfAppliedAfterAnApplierError(): void
    {
        $channel = MySqlReplicationReport::build(Engine::MariaDb, self::rows('ma-applier-error'), ['gtid_slave_pos' => '0-1-6'], null, [], [])->channels[0];

        self::assertSame(ThreadState::Stopped, $channel->applier);
        self::assertSame('0-1-7', $channel->received);
        self::assertSame('0-1-6', $channel->applied);
        self::assertTrue($channel->pendingApply);
        self::assertNotNull($channel->applierError);
        self::assertStringContainsString('Duplicate entry', $channel->applierError->message);
    }

    public function testMariaDbReceiverStatesAndErrors(): void
    {
        $stopped = MySqlReplicationReport::build(Engine::MariaDb, self::rows('ma-receiver-stopped'), [], null, [], [])->channels[0];
        $failing = MySqlReplicationReport::build(Engine::MariaDb, self::rows('ma-receiver-error'), [], null, [], [])->channels[0];

        self::assertSame(ThreadState::Stopped, $stopped->receiver);
        self::assertSame(ThreadState::Connecting, $failing->receiver);
        self::assertNotNull($failing->receiverError);
        self::assertStringNotContainsString('db-source', $failing->receiverError->message);
        self::assertStringNotContainsString('10.1.2.3', $failing->receiverError->message);
    }

    public function testASourceWithReplicas(): void
    {
        $report = MySqlReplicationReport::build(Engine::MySql, [], ['binlog_format' => 'ROW', 'gtid_mode' => 'ON'], \count(self::rows('my-source-replicas')), [], []);

        self::assertSame(ReplicationRole::Source, $report->role);
        self::assertSame([], $report->channels);
        self::assertSame(1, $report->replicaCount);
        self::assertSame([], $report->alerts());
    }

    public function testAServerWithoutReplicationSaysSoWithoutAlerts(): void
    {
        $report = MySqlReplicationReport::build(Engine::MySql, [], ['binlog_format' => 'ROW', 'gtid_mode' => 'OFF', 'read_only' => 'OFF', 'super_read_only' => 'OFF'], 0, [], []);

        self::assertTrue($report->status->isAvailable());
        self::assertSame(ReplicationRole::None, $report->role);
        self::assertSame([], $report->channels);
        self::assertSame([], $report->alerts());
    }

    public function testASourceWhoseReplicasCannotBeCountedIsNotClaimedToHaveNone(): void
    {
        $report = MySqlReplicationReport::build(Engine::MySql, [], [], null, [], []);

        self::assertSame(ReplicationRole::None, $report->role);
        self::assertNull($report->replicaCount);
    }

    public function testMariaDbWithoutGtidOnTheChannelIsReported(): void
    {
        $rows = self::rows('ma-healthy');
        $rows[0]['Using_Gtid'] = 'No';
        $report = MySqlReplicationReport::build(Engine::MariaDb, $rows, [], null, [], []);

        self::assertFalse($report->settings->gtid);
    }

    public function testAMySqlWithGtidOffIsReported(): void
    {
        $report = MySqlReplicationReport::build(Engine::MySql, self::rows('my-healthy'), ['gtid_mode' => 'OFF_PERMISSIVE'], null, [], []);

        self::assertFalse($report->settings->gtid);
        self::assertSame(DiagnosticStatus::available()->availability, $report->status->availability);
    }

    public function testALongGtidSetIsBounded(): void
    {
        $rows = self::rows('my-healthy');
        $rows[0]['Executed_Gtid_Set'] = implode(",\n", array_map(static fn (int $i): string => \sprintf('%08x-0000-0000-0000-000000000000:1-%d', $i, $i), range(1, 500)));

        $channel = MySqlReplicationReport::build(Engine::MySql, $rows, [], null, [], [])->channels[0];

        self::assertNotNull($channel->applied);
        self::assertLessThanOrEqual(2001, mb_strlen($channel->applied));
        self::assertStringEndsWith('…', $channel->applied);
    }
}
