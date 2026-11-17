<?php

declare(strict_types=1);

namespace App\Tests\Core\Connection\Tunnel;

use App\Core\Connection\Exception\ForbiddenBastionException;
use App\Core\Connection\SshJump;
use App\Core\Connection\Tunnel\AllowedBastionTunnelOpener;
use App\Core\Connection\Tunnel\BastionAllowList;
use App\Core\Connection\Tunnel\TunnelEndpoint;
use App\Core\Connection\Tunnel\TunnelRequest;
use PHPUnit\Framework\TestCase;

final class AllowedBastionTunnelOpenerTest extends TestCase
{
    private const KEY = "-----BEGIN OPENSSH PRIVATE KEY-----\nAAAAfaux\n-----END OPENSSH PRIVATE KEY-----\n";

    private function request(string $bastion = 'bastion.example'): TunnelRequest
    {
        return new TunnelRequest(new SshJump($bastion, 22, 'tunnel', self::KEY), 'db.internal', 5432);
    }

    private function inner(): RecordingTunnelOpener
    {
        return new RecordingTunnelOpener();
    }

    public function testAnAllowedBastionIsOpenedByTheInnerOpener(): void
    {
        $inner = $this->inner();
        $opener = new AllowedBastionTunnelOpener($inner, BastionAllowList::fromString('bastion.example'));

        $endpoint = $opener->open($this->request());

        self::assertSame('/run/tunnels/abc/tunnel.sock', $endpoint->socketPath);
        self::assertSame(1, $inner->opened);
    }

    public function testAnUnlistedBastionNeverReachesTheInnerOpener(): void
    {
        $inner = $this->inner();
        $opener = new AllowedBastionTunnelOpener($inner, BastionAllowList::fromString(''));

        try {
            $opener->open($this->request());
            self::fail('refused expected');
        } catch (ForbiddenBastionException) {
            self::assertSame(0, $inner->opened);
        }
    }

    public function testClosingIsNotSubjectToTheList(): void
    {
        $inner = $this->inner();
        (new AllowedBastionTunnelOpener($inner, BastionAllowList::fromString('')))->close(new TunnelEndpoint('abc', '/run/tunnels/abc/tunnel.sock'));

        self::assertSame(1, $inner->closed);
    }

    public function testARequestHidesTheKeyFromDumpsAndRefusesSerialization(): void
    {
        $request = $this->request();

        self::assertStringNotContainsString('AAAAfaux', print_r($request, true));
        $this->expectException(\LogicException::class);
        serialize($request);
    }

    public function testARequestRefusesAnInventedSocketNameOrIdle(): void
    {
        $jump = new SshJump('bastion.example', 22, 'tunnel', self::KEY);
        $refused = 0;
        foreach ([['../x', 300], ['a/b', 300], ['tunnel.sock', 0], ['tunnel.sock', 301]] as [$name, $idle]) {
            try {
                new TunnelRequest($jump, 'db.internal', 5432, $name, $idle);
            } catch (\App\Core\Connection\Exception\InvalidConnectionParametersException) {
                ++$refused;
            }
        }
        self::assertSame(4, $refused);
        self::assertSame('.s.PGSQL.5432', (new TunnelRequest($jump, 'db.internal', 5432, '.s.PGSQL.5432'))->socketName);
    }
}
