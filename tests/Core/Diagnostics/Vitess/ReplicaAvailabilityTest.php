<?php

declare(strict_types=1);

namespace App\Tests\Core\Diagnostics\Vitess;

use App\Core\Diagnostics\Vitess\ReplicaAvailability;
use App\Core\Diagnostics\Vitess\VitessTablet;
use PHPUnit\Framework\TestCase;

final class ReplicaAvailabilityTest extends TestCase
{
    private static function tablet(string $keyspace, string $type, string $state = 'SERVING', string $alias = 'test-0000000001'): VitessTablet
    {
        $tablet = VitessTablet::fromRow(['Cell' => 'test', 'Keyspace' => $keyspace, 'Shard' => '0', 'TabletType' => $type, 'State' => $state, 'Alias' => $alias]);
        self::assertNotNull($tablet);

        return $tablet;
    }

    public function testAServingReplicaOfTheKeyspaceIsAvailable(): void
    {
        $tablets = [self::tablet('shop', 'PRIMARY'), self::tablet('shop', 'REPLICA', alias: 'test-0000000002')];

        self::assertSame(ReplicaAvailability::Available, ReplicaAvailability::fromTablets('shop', $tablets));
    }

    public function testAPrimaryAloneIsMissing(): void
    {
        self::assertSame(ReplicaAvailability::Missing, ReplicaAvailability::fromTablets('shop', [self::tablet('shop', 'PRIMARY')]));
        self::assertSame(ReplicaAvailability::Missing, ReplicaAvailability::fromTablets('shop', []));
    }

    public function testAReplicaThatDoesNotServeOrBelongsToAnotherKeyspaceDoesNotCount(): void
    {
        $tablets = [self::tablet('shop', 'REPLICA', 'NOT_SERVING'), self::tablet('other', 'REPLICA', alias: 'test-0000000003'), self::tablet('shop', 'RDONLY', alias: 'test-0000000004')];

        self::assertSame(ReplicaAvailability::Missing, ReplicaAvailability::fromTablets('shop', $tablets), '@replica ne vise ni un réplica à l’arrêt, ni un autre keyspace, ni un rdonly');
    }

    public function testTheTargetSuffixOfTheDatabaseNameIsIgnored(): void
    {
        $tablets = [self::tablet('shop', 'REPLICA')];

        self::assertSame(ReplicaAvailability::Available, ReplicaAvailability::fromTablets('shop@primary', $tablets));
        self::assertSame(ReplicaAvailability::Available, ReplicaAvailability::fromTablets('shop:0@replica', $tablets));
    }

    public function testWithoutADatabaseAnyKeyspaceCounts(): void
    {
        self::assertSame(ReplicaAvailability::Available, ReplicaAvailability::fromTablets('', [self::tablet('shop', 'REPLICA')]));
    }
}
