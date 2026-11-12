<?php

declare(strict_types=1);

namespace App\Tests\Integration\Audit;

use App\Audit\AuditAction;
use App\Audit\AuditActor;
use App\Audit\AuditContext;
use App\Audit\AuditedTunnels;
use App\Audit\AuditLog;
use App\Audit\AuditOutcome;
use App\Core\Connection\Exception\ForbiddenBastionException;
use App\Core\Connection\SshJump;
use App\Core\Connection\Tunnel\AllowedBastionTunnelOpener;
use App\Core\Connection\Tunnel\BastionAllowList;
use App\Core\Connection\Tunnel\TunnelRequest;
use App\Repository\AuditEventRepository;
use App\Tests\Core\Connection\Tunnel\RecordingTunnelOpener;
use App\Tests\Integration\Persistence\DatabaseTestCase;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Uid\Uuid;

#[Group('integration')]
final class AuditedTunnelsTest extends DatabaseTestCase
{
    private const KEY = "-----BEGIN OPENSSH PRIVATE KEY-----\nAAAAfaux\n-----END OPENSSH PRIVATE KEY-----\n";

    private function tunnels(string $allowed, RecordingTunnelOpener $inner): AuditedTunnels
    {
        $events = self::getContainer()->get(AuditEventRepository::class);
        self::assertInstanceOf(AuditEventRepository::class, $events);

        return new AuditedTunnels(
            new AllowedBastionTunnelOpener($inner, BastionAllowList::fromString($allowed)),
            new AuditLog($events, new MockClock('2026-11-01 10:00:00 UTC')),
        );
    }

    public function testARefusedBastionIsAuditedWithItsAddressAndPortOnly(): void
    {
        $user = Uuid::v7();
        $context = new AuditContext(AuditActor::Web, $user, null, '203.0.113.7');
        $request = new TunnelRequest(new SshJump('bastion.example', 2222, 'tunnel', self::KEY), 'db.internal', 5432);

        try {
            $this->tunnels('', new RecordingTunnelOpener())->open($context, $request);
            self::fail('refused expected');
        } catch (ForbiddenBastionException) {
        }
        $this->entityManager->clear();

        $events = self::getContainer()->get(AuditEventRepository::class);
        self::assertInstanceOf(AuditEventRepository::class, $events);
        $found = $events->findForUser($user);
        self::assertCount(1, $found);
        self::assertSame(AuditAction::TargetForbidden, $found[0]->getAction());
        self::assertSame(AuditOutcome::Refused, $found[0]->getOutcome());
        self::assertSame(['host' => 'bastion.example', 'port' => 2222], $found[0]->getDetails());
    }

    public function testAnAllowedBastionLeavesNoTrace(): void
    {
        $user = Uuid::v7();
        $inner = new RecordingTunnelOpener();
        $request = new TunnelRequest(new SshJump('bastion.example', 22, 'tunnel', self::KEY), 'db.internal', 5432);

        $this->tunnels('bastion.example', $inner)->open(new AuditContext(AuditActor::Web, $user, null, null), $request);
        $this->entityManager->clear();

        $events = self::getContainer()->get(AuditEventRepository::class);
        self::assertInstanceOf(AuditEventRepository::class, $events);
        self::assertSame(1, $inner->opened);
        self::assertSame([], $events->findForUser($user));
    }
}
