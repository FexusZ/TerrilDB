<?php

declare(strict_types=1);

namespace App\Tests\Core\Connection;

use App\Core\Connection\LocalNetworks;
use PHPUnit\Framework\TestCase;

final class LocalNetworksTest extends TestCase
{
    // Sortie réelle de /proc/net/route dans un conteneur : défaut par une passerelle, un réseau relié en direct.
    private const ROUTES = <<<'ROUTES'
        Iface	Destination	Gateway 	Flags	RefCnt	Use	Metric	Mask		MTU	Window	IRTT
        eth0	00000000	010AD50A	0003	0	0	0	00000000	0	0	0
        eth0	0028D50A	00000000	0001	0	0	0	00FFFFFF	0	0	0
        eth1	0030A8C0	00000000	0001	0	0	0	00F0FFFF	0	0	0
        ROUTES;

    public function testReadsDirectlyConnectedNetworksOnly(): void
    {
        $networks = LocalNetworks::fromRoutes(self::ROUTES);

        self::assertSame(['10.213.40.0/24', '192.168.48.0/20'], $networks->cidrs());
    }

    public function testCoversAnAddressInsideANetwork(): void
    {
        $networks = LocalNetworks::fromRoutes(self::ROUTES);

        self::assertTrue($networks->covers('192.168.50.7'));
        self::assertTrue($networks->covers('10.213.40.200'));
        self::assertFalse($networks->covers('192.168.64.1'));
        self::assertFalse($networks->covers('10.213.41.1'));
        self::assertFalse($networks->covers('8.8.8.8'));
    }

    public function testNeverCoversTheDefaultRouteNorIpv6OrGarbage(): void
    {
        $networks = LocalNetworks::fromRoutes(self::ROUTES);

        self::assertFalse($networks->covers('::1'));
        self::assertFalse($networks->covers('not-an-address'));
        self::assertFalse($networks->covers(''));
    }

    public function testIgnoresLoopbackAndMalformedLines(): void
    {
        $networks = LocalNetworks::fromRoutes("lo\t0000007F\t00000000\t0001\t0\t0\t0\t000000FF\t0\t0\t0\nzzz\nx y z\n");

        self::assertSame([], $networks->cidrs());
    }

    public function testAnUnreadableTableCoversNothing(): void
    {
        self::assertFalse(LocalNetworks::fromRoutes('')->covers('192.168.1.1'));
    }
}
