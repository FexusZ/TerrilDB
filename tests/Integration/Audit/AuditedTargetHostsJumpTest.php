<?php

declare(strict_types=1);

namespace App\Tests\Integration\Audit;

use App\Audit\AuditAction;
use App\Audit\AuditedTargetHosts;
use App\Audit\AuditLog;
use App\Connection\Exception\ForbiddenTargetHostException;
use App\Connection\HostResolver;
use App\Connection\TargetHostPolicy;
use App\Core\Connection\ConnectionParameters;
use App\Core\Connection\Exception\ForbiddenBastionException;
use App\Core\Connection\SshJump;
use App\Core\Connection\Tunnel\BastionAllowList;
use App\Crypto\KeyDerivation;
use App\Entity\User;
use App\Repository\AuditEventRepository;
use App\Repository\UserRepository;
use App\Tests\Integration\Persistence\DatabaseTestCase;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpFoundation\RequestStack;

#[Group('integration')]
final class AuditedTargetHostsJumpTest extends DatabaseTestCase
{
    private const KEY = "-----BEGIN OPENSSH PRIVATE KEY-----\nAAAAfaux\n-----END OPENSSH PRIVATE KEY-----\n";

    private function member(): User
    {
        $user = new User('person@example.test', 'person', '$argon2id$v=19$m=16384,t=3,p=1$c29tZXNhbHQ$aGFzaGhhc2hoYXNoaGFzaA', (new KeyDerivation(opsLimit: 1, memLimit: 8192))->newParameters(), new \DateTimeImmutable('2026-11-01T10:00:00Z'));
        $users = self::getContainer()->get(UserRepository::class);
        self::assertInstanceOf(UserRepository::class, $users);
        $users->save($user);

        return $user;
    }

    private function hosts(string $bastions = '93.184.216.34:2222,127.0.0.1:2222,bastion.example'): AuditedTargetHosts
    {
        $events = self::getContainer()->get(AuditEventRepository::class);
        self::assertInstanceOf(AuditEventRepository::class, $events);
        $resolver = new class implements HostResolver {
            public function resolve(string $host): array
            {
                return filter_var($host, \FILTER_VALIDATE_IP) ? [$host] : [];
            }
        };

        return new AuditedTargetHosts(new TargetHostPolicy($resolver, null), new AuditLog($events, new MockClock('2026-11-01 10:00:00 UTC')), new RequestStack(), BastionAllowList::fromString($bastions));
    }

    /**
     * @return array<string, mixed>
     */
    private function lastDetails(User $user): array
    {
        $this->entityManager->clear();
        $events = self::getContainer()->get(AuditEventRepository::class);
        self::assertInstanceOf(AuditEventRepository::class, $events);
        $found = $events->findForUser($user->getId());
        self::assertCount(1, $found);
        self::assertSame(AuditAction::TargetForbidden, $found[0]->getAction());

        return $found[0]->getDetails();
    }

    public function testARefusedFinalTargetIsAuditedWithItsOwnAddressAndMarkedBehindAJump(): void
    {
        $user = $this->member();
        $parameters = new ConnectionParameters('127.0.0.1', 6543, 'shop', 'reader', 'secret', transport: new SshJump('93.184.216.34', 2222, 'jump', self::KEY));

        try {
            $this->hosts()->assertAllowed($user, $parameters);
            self::fail('refused expected');
        } catch (ForbiddenTargetHostException) {
        }

        self::assertSame(['host' => '127.0.0.1', 'port' => 6543, 'via' => 'jump'], $this->lastDetails($user));
    }

    public function testARefusedJumpIsAuditedWithTheJumpAddress(): void
    {
        $user = $this->member();
        $parameters = new ConnectionParameters('db.example', 5432, 'shop', 'reader', 'secret', transport: new SshJump('127.0.0.1', 2222, 'jump', self::KEY));

        try {
            $this->hosts()->assertAllowed($user, $parameters);
            self::fail('refused expected');
        } catch (ForbiddenTargetHostException) {
        }

        self::assertSame(['host' => '127.0.0.1', 'port' => 2222, 'via' => 'jump'], $this->lastDetails($user));
    }

    public function testABastionOutsideTheListIsRefusedAndAudited(): void
    {
        $user = $this->member();
        $parameters = new ConnectionParameters('db.example', 5432, 'shop', 'reader', 'secret', transport: new SshJump('93.184.216.34', 22, 'jump', self::KEY));

        try {
            $this->hosts()->assertAllowed($user, $parameters);
            self::fail('refused expected');
        } catch (ForbiddenBastionException) {
        }

        self::assertSame(['host' => '93.184.216.34', 'port' => 22, 'via' => 'jump'], $this->lastDetails($user));
    }

    public function testAListedBastionPasses(): void
    {
        $parameters = new ConnectionParameters('db.example', 5432, 'shop', 'reader', 'secret', transport: new SshJump('93.184.216.34', 2222, 'jump', self::KEY));

        $this->hosts()->assertAllowed($this->member(), $parameters);

        $this->addToAssertionCount(1);
    }

    public function testABastionToReadTheHostKeyOfIsJudgedLikeAJump(): void
    {
        $user = $this->member();

        $this->hosts()->assertBastion($user, '93.184.216.34', 2222);
        $this->addToAssertionCount(1);

        try {
            $this->hosts()->assertBastion($user, '127.0.0.1', 2222);
            self::fail('refused expected');
        } catch (ForbiddenTargetHostException) {
        }
        self::assertSame(['host' => '127.0.0.1', 'port' => 2222, 'via' => 'jump'], $this->lastDetails($user));
    }
}
