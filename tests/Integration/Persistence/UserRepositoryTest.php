<?php

declare(strict_types=1);

namespace App\Tests\Integration\Persistence;

use App\Crypto\KeyDerivation;
use App\Entity\User;
use App\Repository\UserRepository;
use App\Security\AuthPasswordHasher;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\OptimisticLockException;
use PHPUnit\Framework\Attributes\Group;

#[Group('integration')]
final class UserRepositoryTest extends DatabaseTestCase
{
    private UserRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();

        $repository = self::getContainer()->get(UserRepository::class);
        self::assertInstanceOf(UserRepository::class, $repository);
        $this->repository = $repository;
    }

    private function user(string $email = 'alice@example.test', string $username = 'alice'): User
    {
        return new User(
            $email,
            $username,
            (new AuthPasswordHasher(opsLimit: 3, memLimit: 16384))->hash('correct horse battery'),
            (new KeyDerivation(opsLimit: 1, memLimit: 8192))->newParameters(),
            new \DateTimeImmutable('2026-10-29T12:00:00Z'),
        );
    }

    private function saveAndForget(User $user): void
    {
        $this->repository->save($user);
        $this->entityManager->clear();
    }

    public function testASavedUserComesBackWithEveryField(): void
    {
        $user = $this->user();
        $user->enableTotp('v1:encrypted-secret', ['hash-1', 'hash-2']);
        $user->recordTotpStep(42);
        $this->saveAndForget($user);

        $found = $this->repository->findByLogin('alice@example.test');

        self::assertNotNull($found);
        self::assertTrue($user->getId()->equals($found->getId()));
        self::assertSame('alice@example.test', $found->getEmail());
        self::assertSame('alice', $found->getUsername());
        self::assertSame($user->getPasswordHash(), $found->getPasswordHash());
        self::assertSame($user->getKdfParameters()->toString(), $found->getKdfParameters()->toString());
        self::assertSame('v1:encrypted-secret', $found->getTotpSecret());
        self::assertSame(42, $found->getTotpLastStep());
        self::assertSame(['hash-1', 'hash-2'], $found->getRecoveryCodeHashes());
        self::assertEquals(new \DateTimeImmutable('2026-10-29T12:00:00Z'), $found->getCreatedAt());
    }

    public function testFindsByEmailOrUsernameWhateverTheCase(): void
    {
        $this->saveAndForget($this->user());

        self::assertSame('alice', $this->repository->findByLogin('ALICE@Example.test')?->getUsername());
        self::assertSame('alice', $this->repository->findByLogin('Alice')?->getUsername());
        self::assertSame('alice', $this->repository->findByLogin('  alice  ')?->getUsername());
    }

    public function testUnknownLoginFindsNothing(): void
    {
        $this->saveAndForget($this->user());

        self::assertNull($this->repository->findByLogin('bob'));
        self::assertNull($this->repository->findByLogin('bob@example.test'));
        self::assertNull($this->repository->findByLogin(''));
    }

    public function testAnEmailShapedLoginIsNeverMatchedAgainstUsernames(): void
    {
        $this->saveAndForget($this->user('alice@example.test', 'alice'));

        self::assertNull($this->repository->findByLogin('alice@elsewhere.test'));
    }

    public function testEmailsAreUnique(): void
    {
        $this->repository->save($this->user('alice@example.test', 'alice'));

        $this->expectException(UniqueConstraintViolationException::class);
        $this->repository->save($this->user('ALICE@example.test', 'someone-else'));
    }

    public function testUsernamesAreUnique(): void
    {
        $this->repository->save($this->user('alice@example.test', 'alice'));

        $this->expectException(UniqueConstraintViolationException::class);
        $this->repository->save($this->user('other@example.test', 'Alice'));
    }

    public function testChangesToAKnownUserArePersisted(): void
    {
        $user = $this->user();
        $this->saveAndForget($user);

        $found = $this->repository->findByLogin('alice');
        self::assertNotNull($found);
        $found->enableTotp('v1:new-secret', ['hash-9']);
        $this->repository->save($found);
        $this->entityManager->clear();

        self::assertSame('v1:new-secret', $this->repository->findByLogin('alice')?->getTotpSecret());
    }

    public function testAWriteMadeByAnotherProcessSinceTheReadIsDetected(): void
    {
        $this->saveAndForget($this->user());
        $user = $this->repository->findByLogin('alice');
        self::assertNotNull($user);

        // Un autre processus enregistre sa modification après notre lecture (ex. même code TOTP présenté deux fois).
        $this->entityManager->getConnection()->executeStatement(
            'UPDATE app_user SET totp_last_step = 99, version = version + 1 WHERE username = ?',
            ['alice'],
        );

        $user->enableTotp('v1:too-late', []);
        $this->expectException(OptimisticLockException::class);
        $this->repository->save($user);
    }

    public function testCountsUsers(): void
    {
        self::assertSame(0, $this->repository->count([]));

        $this->repository->save($this->user());

        self::assertSame(1, $this->repository->count([]));
    }
}
