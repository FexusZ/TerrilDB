<?php

declare(strict_types=1);

namespace App\Tests\Driver\PostgreSql;

use App\Core\Diagnostics\Availability;
use App\Core\Diagnostics\Replication\ReplicationExtras;
use App\Core\Diagnostics\Replication\ReplicationRole;
use App\Driver\PostgreSql\PostgreSqlReplicationReport;
use App\Driver\PostgreSql\PostgreSqlReplicationSections;
use PHPUnit\Framework\TestCase;

/**
 * Lignes réelles de pg_replication_slots et de pg_stat_replication (PostgreSQL 18, paire primaire / réplica) ; les états
 * `extended`, `unreserved` et `lost` sont synthétiques (la cible n'a pas de plafond de WAL), de même forme.
 */
final class PostgreSqlReplicationSectionsTest extends TestCase
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

    public function testAnActiveSlotThatRetainsNothing(): void
    {
        $slots = PostgreSqlReplicationSections::slots(self::rows('pg-slots-healthy'));

        self::assertSame(Availability::Available, $slots->status->availability);
        self::assertCount(1, $slots->slots);
        self::assertSame('replica_slot', $slots->slots[0]->name);
        self::assertSame('physical', $slots->slots[0]->type);
        self::assertTrue($slots->slots[0]->active);
        self::assertFalse($slots->slots[0]->temporary);
        self::assertSame(0, $slots->slots[0]->retainedBytes);
        self::assertSame('reserved', $slots->slots[0]->walStatus);
        self::assertNull($slots->slots[0]->safeWalSize);
    }

    public function testAnInactiveSlotKeepsItsRetainedWalAndRaisesAnAlert(): void
    {
        $slots = PostgreSqlReplicationSections::slots(self::rows('pg-slots-inactive'));

        self::assertSame('orphan_slot', $slots->slots[0]->name);
        self::assertFalse($slots->slots[0]->active);
        self::assertSame(50331704, $slots->slots[0]->retainedBytes);
        $report = PostgreSqlReplicationReport::build(['in_recovery' => false, 'replica_count' => 0], new ReplicationExtras(slots: $slots));

        self::assertSame(ReplicationRole::None, $report->role);
        self::assertSame(['slot_inactive'], array_map(static fn ($a): string => $a->code->value, $report->alerts()));
    }

    public function testSyntheticWalStatusesAndSafeSize(): void
    {
        $row = ['name' => 's', 'type' => 'logical', 'active' => 't', 'temporary' => 'f', 'wal_status' => 'unreserved', 'safe_wal_size' => '1048576', 'retained_bytes' => '2147483648'];

        $slot = PostgreSqlReplicationSections::slots([$row])->slots[0];

        self::assertSame('logical', $slot->type);
        self::assertSame('unreserved', $slot->walStatus);
        self::assertSame(1048576, $slot->safeWalSize);
        self::assertSame(2147483648, $slot->retainedBytes);
    }

    public function testNoSlotIsAnAvailableEmptyList(): void
    {
        $slots = PostgreSqlReplicationSections::slots([]);

        self::assertSame(Availability::Available, $slots->status->availability);
        self::assertSame([], $slots->slots);
    }

    public function testASlotListIsBoundedAt200(): void
    {
        $rows = [];
        for ($i = 0; $i < 201; ++$i) {
            $rows[] = ['name' => 's'.$i, 'type' => 'physical', 'active' => true, 'temporary' => false, 'wal_status' => null, 'safe_wal_size' => null, 'retained_bytes' => null];
        }

        self::assertCount(200, PostgreSqlReplicationSections::slots($rows)->slots);
        self::assertNull(PostgreSqlReplicationSections::slots($rows)->slots[0]->walStatus);
        self::assertNull(PostgreSqlReplicationSections::slots($rows)->slots[0]->retainedBytes);
    }

    public function testAStreamingReplicaSeenFromItsSource(): void
    {
        $links = PostgreSqlReplicationSections::links(self::rows('pg-links-streaming'));

        self::assertSame(Availability::Available, $links->status->availability);
        self::assertCount(1, $links->replicas);
        $link = $links->replicas[0];
        self::assertSame(1, $link->rank);
        self::assertSame('streaming', $link->state);
        self::assertTrue($link->connected);
        self::assertSame('async', $link->syncState);
        self::assertEqualsWithDelta(0.000977, $link->writeLagSeconds, 1e-9);
        self::assertEqualsWithDelta(0.009603, $link->replayLagSeconds, 1e-9);
        self::assertSame(0, $link->behindBytes);
    }

    public function testACatchingUpReplicaIsNotConnectedAndUnknownLagsStayNull(): void
    {
        $link = PostgreSqlReplicationSections::links([['rank' => 2, 'state' => 'catchup', 'sync_state' => 'sync', 'write_lag' => null, 'flush_lag' => null, 'replay_lag' => null, 'behind_bytes' => '42']])->replicas[0];

        self::assertFalse($link->connected);
        self::assertNull($link->writeLagSeconds);
        self::assertNull($link->worstLagSeconds());
        self::assertSame(42, $link->behindBytes);
    }

    public function testNoReplicaIsAnAvailableEmptyList(): void
    {
        self::assertSame([], PostgreSqlReplicationSections::links([])->replicas);
        self::assertSame(Availability::Available, PostgreSqlReplicationSections::links([])->status->availability);
    }
}
