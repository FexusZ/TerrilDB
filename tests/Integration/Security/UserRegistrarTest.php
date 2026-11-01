<?php

declare(strict_types=1);

namespace App\Tests\Integration\Security;

use App\Crypto\KeyDerivation;
use App\Entity\Exception\InvalidUserException;
use App\Entity\Role;
use App\Repository\UserRepository;
use App\Security\AuthPasswordHasher;
use App\Security\Exception\AccountAlreadyExistsException;
use App\Security\Exception\PasswordPolicyException;
use App\Security\PasswordPolicy;
use App\Security\UserRegistrar;
use App\Tests\Integration\Persistence\DatabaseTestCase;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Clock\MockClock;

#[Group('integration')]
final class UserRegistrarTest extends DatabaseTestCase
{
    private const PASSWORD = 'correct horse battery';

    private UserRepository $repository;
    private AuthPasswordHasher $hasher;
    private KeyDerivation $derivation;
    private UserRegistrar $registrar;

    protected function setUp(): void
    {
        parent::setUp();

        $repository = self::getContainer()->get(UserRepository::class);
        self::assertInstanceOf(UserRepository::class, $repository);
        $this->repository = $repository;

        // Coûts réduits : la suite vérifie le comportement, pas la dureté d'Argon2id.
        $this->hasher = new AuthPasswordHasher(opsLimit: 3, memLimit: 16384);
        $this->derivation = new KeyDerivation(opsLimit: 1, memLimit: 8192);
        $this->registrar = new UserRegistrar(
            new PasswordPolicy(),
            $this->hasher,
            $this->derivation,
            $this->repository,
            new MockClock('2026-10-29 12:00:00 UTC'),
        );
    }

    public function testTheFirstAccountAdministratesTheInstallationAndTheNextOnesAreMembers(): void
    {
        $first = $this->registrar->register('alice@example.test', 'alice', self::PASSWORD);
        $second = $this->registrar->register('bob@example.test', 'bob', self::PASSWORD);

        self::assertSame(Role::Admin, $first->getRole());
        self::assertSame(Role::Member, $second->getRole());
    }

    public function testRegistersAPersistedUserWithItsCreationDate(): void
    {
        $user = $this->registrar->register('alice@example.test', 'alice', self::PASSWORD);
        $this->entityManager->clear();

        $found = $this->repository->findByLogin('alice');
        self::assertNotNull($found);
        self::assertTrue($user->getId()->equals($found->getId()));
        self::assertSame('alice@example.test', $found->getEmail());
        self::assertSame('2026-10-29T12:00:00+00:00', $found->getCreatedAt()->format(\DATE_ATOM));
    }

    public function testThePasswordIsStoredOnlyAsAnAuthenticationHash(): void
    {
        $this->registrar->register('alice@example.test', 'alice', self::PASSWORD);
        $this->entityManager->clear();

        $found = $this->repository->findByLogin('alice');
        self::assertNotNull($found);
        self::assertTrue($this->hasher->verify($found->getPasswordHash(), self::PASSWORD));
        self::assertFalse($this->hasher->verify($found->getPasswordHash(), 'another password'));
        self::assertStringNotContainsString(self::PASSWORD, $found->getPasswordHash());
    }

    public function testStoredKdfParametersDeriveTheUsersKey(): void
    {
        $user = $this->registrar->register('alice@example.test', 'alice', self::PASSWORD);

        $first = $this->derivation->derive(self::PASSWORD, $user->getKdfParameters());
        $second = $this->derivation->derive(self::PASSWORD, $user->getKdfParameters());
        $other = $this->derivation->derive('another password', $user->getKdfParameters());

        self::assertSame($first->bytes(), $second->bytes());
        self::assertNotSame($first->bytes(), $other->bytes());
    }

    public function testEachUserGetsItsOwnKdfSalt(): void
    {
        $alice = $this->registrar->register('alice@example.test', 'alice', self::PASSWORD);
        $bob = $this->registrar->register('bob@example.test', 'bob', self::PASSWORD);

        self::assertNotSame($alice->getKdfParameters()->salt, $bob->getKdfParameters()->salt);
    }

    public function testTheAuthenticationHashIsNotTheEncryptionKey(): void
    {
        $user = $this->registrar->register('alice@example.test', 'alice', self::PASSWORD);

        $key = $this->derivation->derive(self::PASSWORD, $user->getKdfParameters());

        self::assertStringNotContainsString($key->bytes(), $user->getPasswordHash());
    }

    public function testWeakPasswordsAreRefusedAndNothingIsStored(): void
    {
        try {
            $this->registrar->register('alice@example.test', 'alice', 'too short');
            self::fail('Expected a policy violation.');
        } catch (PasswordPolicyException) {
            self::assertSame(0, $this->repository->count([]));
        }
    }

    public function testInvalidIdentifiersAreRefusedAndNothingIsStored(): void
    {
        try {
            $this->registrar->register('not-an-email', 'alice', self::PASSWORD);
            self::fail('Expected an invalid user.');
        } catch (InvalidUserException) {
            self::assertSame(0, $this->repository->count([]));
        }
    }

    public function testAnExistingEmailIsRefusedWhateverItsCase(): void
    {
        $this->registrar->register('alice@example.test', 'alice', self::PASSWORD);

        $this->expectException(AccountAlreadyExistsException::class);

        $this->registrar->register('ALICE@example.test', 'someone-else', self::PASSWORD);
    }

    public function testAnExistingUsernameIsRefusedWhateverItsCase(): void
    {
        $this->registrar->register('alice@example.test', 'alice', self::PASSWORD);

        $this->expectException(AccountAlreadyExistsException::class);

        $this->registrar->register('other@example.test', 'Alice', self::PASSWORD);
    }
}
