<?php

declare(strict_types=1);

namespace App\Tests\ScriptRun\Channel;

use App\Audit\AuditActor;
use App\Audit\AuditContext;
use App\Connection\TunnelQuota;
use App\Core\Connection\ConnectionParameters;
use App\Core\Connection\Engine;
use App\Core\Connection\SshJump;
use App\Core\Connection\Tunnel\TooManyTunnelsException;
use App\Core\Connection\Tunnel\TunnelFailedException;
use App\Core\Query\WritePermissions;
use App\ScriptRun\Channel\ScriptTunnelKeeper;
use App\ScriptRun\ScriptRunRequest;
use App\Tests\Core\Connection\Tunnel\RecordingTunnelOpener;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Uid\Uuid;

final class ScriptTunnelKeeperTest extends TestCase
{
    private const KEY = "-----BEGIN OPENSSH PRIVATE KEY-----\nx\n-----END OPENSSH PRIVATE KEY-----";
    private const USER = '0192f1c0-0000-7000-8000-000000000001';

    private RecordingTunnelOpener $tunnels;
    private MockClock $clock;
    private ArrayAdapter $cache;

    private function keeper(): ScriptTunnelKeeper
    {
        $this->tunnels = new RecordingTunnelOpener();
        $this->clock = new MockClock('2026-11-21 10:00:00 UTC');
        $this->cache = new ArrayAdapter();

        return new ScriptTunnelKeeper($this->tunnels, new TunnelQuota($this->cache, $this->clock), $this->clock);
    }

    private static function request(bool $jump = true, Engine $engine = Engine::PostgreSql, ?Uuid $user = null): ScriptRunRequest
    {
        $transport = $jump ? new SshJump('bastion.example.test', 22, 'jump', self::KEY, null) : null;

        return new ScriptRunRequest(
            Uuid::v7(),
            $engine,
            new ConnectionParameters('db.behind.example', 5432, 'shop', 'app', 'pw-unique-2', transport: $transport),
            'SELECT 1',
            new WritePermissions(),
            false,
            false,
            10_000,
            new AuditContext(AuditActor::Web, $user ?? Uuid::fromString(self::USER), null, null),
        );
    }

    public function testAConnectionWithoutJumpPassesUntouched(): void
    {
        $keeper = $this->keeper();
        $request = self::request(false);

        self::assertSame($request, $keeper->prepare($request));
        self::assertSame(0, $this->tunnels->opened);
    }

    public function testTheTunnelIsOpenedForTheTargetBehindTheJumpAndItsSocketIsGiven(): void
    {
        $keeper = $this->keeper();

        $prepared = $keeper->prepare(self::request());

        self::assertSame(1, $this->tunnels->opened);
        self::assertSame('db.behind.example', $this->tunnels->last?->targetHost);
        self::assertSame('.s.PGSQL.5432', $this->tunnels->last->socketName);
        self::assertSame('/run/tunnels/abc/tunnel.sock', $prepared->parameters->socketPath());
    }

    public function testATunnelIsRenewedWhileTheRunLivesButNotBeforeItIsDue(): void
    {
        $keeper = $this->keeper();
        $keeper->prepare(self::request());

        $this->clock->sleep(30);
        $keeper->renew();
        self::assertSame(1, $this->tunnels->opened, 'trop tôt');

        $this->clock->sleep(40);
        $keeper->renew();
        self::assertSame(2, $this->tunnels->opened, 'le relais ferme un tunnel après 300 s sans demande : on le renouvelle');
    }

    public function testReleasingClosesTheTunnelItOpenedAndFreesTheSlot(): void
    {
        $keeper = $this->keeper();
        $request = self::request();
        $keeper->prepare($request);

        $keeper->release($request->runId->toRfc4122());

        self::assertSame(1, $this->tunnels->closed);
        $this->clock->sleep(100);
        $keeper->renew();
        self::assertSame(1, $this->tunnels->opened, 'plus rien à renouveler');
    }

    public function testASharedTunnelIsNotClosedByTheRunThatReusedIt(): void
    {
        $keeper = $this->keeper();
        $this->tunnels->reused = true;
        $request = self::request();
        $keeper->prepare($request);

        $keeper->release($request->runId->toRfc4122());

        self::assertSame(0, $this->tunnels->closed);
    }

    public function testTheAccountQuotaApplies(): void
    {
        $keeper = $this->keeper();
        $this->tunnels->uniqueIds = true;
        for ($n = 0; $n < TunnelQuota::MAX_PER_ACCOUNT; ++$n) {
            $keeper->prepare(self::request());
        }

        $this->expectException(TooManyTunnelsException::class);
        try {
            $keeper->prepare(self::request());
        } finally {
            self::assertSame(TunnelQuota::MAX_PER_ACCOUNT + 1, $this->tunnels->opened);
            self::assertSame(1, $this->tunnels->closed, 'un tunnel ouvert pour rien est refermé');
        }
    }

    public function testAFailedTunnelIsPropagated(): void
    {
        $keeper = $this->keeper();
        $this->tunnels->failure = new TunnelFailedException();

        $this->expectException(TunnelFailedException::class);
        $keeper->prepare(self::request());
    }

    public function testTheRelayOfAnotherEngineNamesTheSocketLikeTheWebFlow(): void
    {
        $keeper = $this->keeper();

        $keeper->prepare(self::request(engine: Engine::MySql));

        self::assertSame('tunnel.sock', $this->tunnels->last?->socketName);
    }
}
