<?php

declare(strict_types=1);

namespace App\Tests\Integration\Persistence;

use App\Core\Connection\Engine;
use App\Crypto\KeyDerivation;
use App\Entity\DatabaseConnection;
use App\Entity\User;
use App\Repository\DatabaseConnectionRepository;
use App\Repository\UserRepository;
use App\Security\AuthPasswordHasher;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Uid\Uuid;

#[Group('integration')]
final class DatabaseConnectionRepositoryTest extends DatabaseTestCase
{
    private DatabaseConnectionRepository $repository;
    private UserRepository $users;

    protected function setUp(): void
    {
        parent::setUp();

        $repository = self::getContainer()->get(DatabaseConnectionRepository::class);
        self::assertInstanceOf(DatabaseConnectionRepository::class, $repository);
        $this->repository = $repository;
        $users = self::getContainer()->get(UserRepository::class);
        self::assertInstanceOf(UserRepository::class, $users);
        $this->users = $users;
    }

    private function user(string $name): User
    {
        $user = new User(
            $name.'@example.test',
            $name,
            (new AuthPasswordHasher(opsLimit: 3, memLimit: 16384))->hash('correct horse battery'),
            (new KeyDerivation(opsLimit: 1, memLimit: 8192))->newParameters(),
            new \DateTimeImmutable('2026-10-29T12:00:00Z'),
        );
        $this->users->save($user);

        return $user;
    }

    private function connection(User $owner, string $name = 'Production', Engine $engine = Engine::PostgreSql): DatabaseConnection
    {
        return new DatabaseConnection(Uuid::v7(), $owner, $name, $engine, 'v1:AAAAencrypted', new \DateTimeImmutable('2026-10-31T10:00:00Z'));
    }

    public function testASavedConnectionComesBackWithEveryField(): void
    {
        $alice = $this->user('alice');
        $connection = $this->connection($alice, 'Production', Engine::MariaDb);
        $this->repository->save($connection);
        $this->entityManager->clear();

        $found = $this->repository->findOwned($alice, $connection->getId());

        self::assertNotNull($found);
        self::assertSame('Production', $found->getName());
        self::assertSame(Engine::MariaDb, $found->getEngine());
        self::assertSame('v1:AAAAencrypted', $found->getEncryptedParameters());
        self::assertTrue($found->belongsTo($alice));
        self::assertSame('2026-10-31T10:00:00+00:00', $found->getCreatedAt()->format(\DATE_ATOM));
    }

    public function testAnotherUsersConnectionIsInvisible(): void
    {
        $alice = $this->user('alice');
        $bob = $this->user('bob');
        $connection = $this->connection($alice);
        $this->repository->save($connection);
        $this->entityManager->clear();

        self::assertNull($this->repository->findOwned($bob, $connection->getId()));
    }

    public function testAnUnknownIdentifierFindsNothing(): void
    {
        self::assertNull($this->repository->findOwned($this->user('alice'), Uuid::v7()));
    }

    public function testListingIsScopedToTheOwnerAndSortedByName(): void
    {
        $alice = $this->user('alice');
        $bob = $this->user('bob');
        $this->repository->save($this->connection($alice, 'Staging'));
        $this->repository->save($this->connection($alice, 'Analytics'));
        $this->repository->save($this->connection($bob, 'Bob only'));
        $this->entityManager->clear();

        $names = array_map(static fn (DatabaseConnection $c): string => $c->getName(), $this->repository->findAllOwnedBy($alice));

        self::assertSame(['Analytics', 'Staging'], $names);
        self::assertSame([], $this->repository->findAllOwnedBy($this->user('carol')));
    }

    public function testAnOwnerCannotHaveTwoConnectionsWithTheSameName(): void
    {
        $alice = $this->user('alice');
        $this->repository->save($this->connection($alice, 'Production'));

        $this->expectException(UniqueConstraintViolationException::class);

        $this->repository->save($this->connection($alice, 'Production'));
    }

    public function testTwoOwnersMayUseTheSameName(): void
    {
        $alice = $this->user('alice');
        $bob = $this->user('bob');
        $this->repository->save($this->connection($alice, 'Production'));
        $this->repository->save($this->connection($bob, 'Production'));

        self::assertCount(1, $this->repository->findAllOwnedBy($alice));
        self::assertCount(1, $this->repository->findAllOwnedBy($bob));
    }

    public function testRemoveDeletesTheConnection(): void
    {
        $alice = $this->user('alice');
        $connection = $this->connection($alice);
        $this->repository->save($connection);
        $id = $connection->getId();

        $this->repository->remove($connection);
        $this->entityManager->clear();

        self::assertNull($this->repository->findOwned($alice, $id));
    }

    public function testDeletingTheUserDeletesItsConnections(): void
    {
        $alice = $this->user('alice');
        $connection = $this->connection($alice);
        $this->repository->save($connection);
        $id = $connection->getId();

        $this->entityManager->getConnection()->executeStatement('DELETE FROM app_user WHERE id = ?', [$alice->getId()->toRfc4122()]);
        $this->entityManager->clear();

        self::assertNull($this->entityManager->find(DatabaseConnection::class, $id));
    }
}
