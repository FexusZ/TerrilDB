<?php

declare(strict_types=1);

namespace App\Tests\Integration\Invitation;

use App\Crypto\KeyDerivation;
use App\Entity\Exception\InvalidInvitationException;
use App\Entity\Role;
use App\Entity\User;
use App\Invitation\Exception\InvalidInvitationTokenException;
use App\Invitation\Exception\InvitationNotFoundException;
use App\Invitation\Exception\NotAnAdministratorException;
use App\Invitation\InvitationService;
use App\Repository\InvitationRepository;
use App\Repository\UserRepository;
use App\Security\AuthPasswordHasher;
use App\Security\Exception\AccountAlreadyExistsException;
use App\Security\Exception\PasswordPolicyException;
use App\Security\PasswordPolicy;
use App\Security\UserRegistrar;
use App\Tests\Integration\Persistence\DatabaseTestCase;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Uid\Uuid;

#[Group('integration')]
final class InvitationServiceTest extends DatabaseTestCase
{
    private const PASSWORD = 'correct horse battery';

    private InvitationRepository $invitations;
    private UserRepository $users;
    private MockClock $clock;
    private InvitationService $service;
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $invitations = self::getContainer()->get(InvitationRepository::class);
        self::assertInstanceOf(InvitationRepository::class, $invitations);
        $this->invitations = $invitations;
        $users = self::getContainer()->get(UserRepository::class);
        self::assertInstanceOf(UserRepository::class, $users);
        $this->users = $users;
        $this->clock = new MockClock('2026-11-01 10:00:00 UTC');
        $registrar = new UserRegistrar(new PasswordPolicy(), new AuthPasswordHasher(opsLimit: 3, memLimit: 16384), new KeyDerivation(opsLimit: 1, memLimit: 8192), $this->users, $this->clock);
        $this->service = new InvitationService($this->invitations, $registrar, $this->entityManager, $this->clock);

        $this->admin = $registrar->register('root@example.test', 'root', self::PASSWORD);
    }

    private function member(string $name = 'bob'): User
    {
        $registrar = new UserRegistrar(new PasswordPolicy(), new AuthPasswordHasher(opsLimit: 3, memLimit: 16384), new KeyDerivation(opsLimit: 1, memLimit: 8192), $this->users, $this->clock);

        return $registrar->register($name.'@example.test', $name, self::PASSWORD);
    }

    public function testAnAdministratorIssuesASingleUseInvitation(): void
    {
        $issued = $this->service->issue($this->admin, 'Pour Marie');

        self::assertMatchesRegularExpression('/^inv_[0-9a-f]{32}_[A-Za-z0-9_-]{43}$/D', $issued->token);
        self::assertSame('Pour Marie', $issued->entity->getNote());
        self::assertSame('2026-11-08T10:00:00+00:00', $issued->entity->getExpiresAt()->format(\DATE_ATOM));
        $secret = substr($issued->token, -43);
        $stored = json_encode($this->entityManager->getConnection()->fetchAllAssociative('SELECT * FROM invitation'), \JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString($secret, $stored);
    }

    public function testTheLifetimeIsBetweenOneAndThirtyDays(): void
    {
        self::assertSame('2026-12-01T10:00:00+00:00', $this->service->issue($this->admin, '', 30)->entity->getExpiresAt()->format(\DATE_ATOM));

        foreach ([0, 31] as $days) {
            try {
                $this->service->issue($this->admin, '', $days);
                self::fail('A lifetime of '.$days.' days must be refused.');
            } catch (InvalidInvitationException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testAMemberCannotInvite(): void
    {
        $this->expectException(NotAnAdministratorException::class);

        $this->service->issue($this->member(), 'Pour Eve');
    }

    public function testRegistersAMemberAndConsumesTheInvitation(): void
    {
        $issued = $this->service->issue($this->admin, 'Pour Marie');

        $user = $this->service->register($issued->token, 'marie@example.test', 'marie', self::PASSWORD);

        self::assertSame(Role::Member, $user->getRole());
        self::assertSame($user, $this->users->findByLogin('marie'));
        self::assertSame($user, $issued->entity->getUsedBy());
        self::assertEquals(new \DateTimeImmutable('2026-11-01T10:00:00+00:00'), $issued->entity->getUsedAt());
    }

    public function testAnInvitationOpensOneAccountOnly(): void
    {
        $issued = $this->service->issue($this->admin, '');
        $this->service->register($issued->token, 'marie@example.test', 'marie', self::PASSWORD);

        $this->expectException(InvalidInvitationTokenException::class);

        $this->service->register($issued->token, 'eve@example.test', 'eve', self::PASSWORD);
    }

    public function testAnExpiredInvitationIsRefused(): void
    {
        $issued = $this->service->issue($this->admin, '', 1);
        $this->clock->modify('+1 day');

        $this->expectException(InvalidInvitationTokenException::class);

        $this->service->register($issued->token, 'marie@example.test', 'marie', self::PASSWORD);
    }

    public function testUnknownMalformedAndAlteredTokensLookTheSame(): void
    {
        $issued = $this->service->issue($this->admin, '');
        $altered = substr($issued->token, 0, -1).('A' === substr($issued->token, -1) ? 'B' : 'A');
        $unknown = 'inv_'.str_repeat('0', 32).'_'.str_repeat('a', 43);

        foreach (['nonsense', $altered, $unknown, ''] as $token) {
            try {
                $this->service->register($token, 'marie@example.test', 'marie', self::PASSWORD);
                self::fail('The token must be refused.');
            } catch (InvalidInvitationTokenException $e) {
                self::assertSame('The invitation is not valid.', $e->getMessage());
            }
        }
        self::assertNull($this->users->findByLogin('marie'));
    }

    public function testAFailedRegistrationLeavesTheInvitationUsable(): void
    {
        $issued = $this->service->issue($this->admin, '');

        try {
            $this->service->register($issued->token, 'marie@example.test', 'marie', 'short');
            self::fail('A weak password must be refused.');
        } catch (PasswordPolicyException) {
            self::assertNull($issued->entity->getUsedAt());
        }

        $user = $this->service->register($issued->token, 'marie@example.test', 'marie', self::PASSWORD);
        self::assertSame('marie', $user->getUsername());
    }

    public function testAnExistingAccountIsNotOverwritten(): void
    {
        $issued = $this->service->issue($this->admin, '');

        $this->expectException(AccountAlreadyExistsException::class);

        $this->service->register($issued->token, 'root@example.test', 'someone', self::PASSWORD);
    }

    public function testListsOnlyTheAdministratorsOwnInvitationsNewestFirst(): void
    {
        $first = $this->service->issue($this->admin, 'première');
        $this->clock->modify('+1 hour');
        $second = $this->service->issue($this->admin, 'seconde');
        $this->entityManager->persist($otherAdmin = new User('other@example.test', 'other', (new AuthPasswordHasher(opsLimit: 3, memLimit: 16384))->hash(self::PASSWORD), (new KeyDerivation(opsLimit: 1, memLimit: 8192))->newParameters(), new \DateTimeImmutable('2026-10-29T12:00:00Z'), Role::Admin));
        $this->entityManager->flush();
        $this->service->issue($otherAdmin, 'pas la mienne');

        self::assertSame([$second->entity, $first->entity], $this->service->list($this->admin));
    }

    public function testListingRequiresAnAdministrator(): void
    {
        $this->expectException(NotAnAdministratorException::class);

        $this->service->list($this->member());
    }

    public function testRevokingMakesTheInvitationUseless(): void
    {
        $issued = $this->service->issue($this->admin, '');

        $this->service->revoke($this->admin, $issued->entity->getId());

        self::assertSame([], $this->service->list($this->admin));
        $this->expectException(InvalidInvitationTokenException::class);
        $this->service->register($issued->token, 'marie@example.test', 'marie', self::PASSWORD);
    }

    public function testRevokingSomethingUnknownOrNotOwnedIsNotFound(): void
    {
        $issued = $this->service->issue($this->admin, '');
        $this->entityManager->persist($otherAdmin = new User('other@example.test', 'other', (new AuthPasswordHasher(opsLimit: 3, memLimit: 16384))->hash(self::PASSWORD), (new KeyDerivation(opsLimit: 1, memLimit: 8192))->newParameters(), new \DateTimeImmutable('2026-10-29T12:00:00Z'), Role::Admin));
        $this->entityManager->flush();

        foreach ([[$otherAdmin, $issued->entity->getId()], [$this->admin, Uuid::v7()]] as [$by, $id]) {
            try {
                $this->service->revoke($by, $id);
                self::fail('The invitation must not be found.');
            } catch (InvitationNotFoundException) {
                $this->addToAssertionCount(1);
            }
        }
        self::assertCount(1, $this->service->list($this->admin));
    }
}
