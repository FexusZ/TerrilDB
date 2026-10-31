<?php

declare(strict_types=1);

namespace App\Tests\Integration\Connection;

use App\Connection\ConnectionManager;
use App\Connection\ConnectionSecretContext;
use App\Connection\Exception\ConnectionNameAlreadyUsedException;
use App\Connection\Exception\ConnectionNotFoundException;
use App\Core\Connection\ConnectionParameters;
use App\Core\Connection\Engine;
use App\Crypto\DerivedKey;
use App\Crypto\Exception\DecryptionFailedException;
use App\Crypto\KeyDerivation;
use App\Crypto\SecretCipher;
use App\Entity\DatabaseConnection;
use App\Entity\Exception\InvalidDatabaseConnectionException;
use App\Entity\User;
use App\Repository\DatabaseConnectionRepository;
use App\Repository\UserRepository;
use App\Security\AuthPasswordHasher;
use App\Tests\Integration\Persistence\DatabaseTestCase;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Uid\Uuid;

#[Group('integration')]
final class ConnectionManagerTest extends DatabaseTestCase
{
    private DatabaseConnectionRepository $repository;
    private MockClock $clock;
    private ConnectionManager $manager;
    private User $alice;
    private DerivedKey $aliceKey;

    protected function setUp(): void
    {
        parent::setUp();

        $repository = self::getContainer()->get(DatabaseConnectionRepository::class);
        self::assertInstanceOf(DatabaseConnectionRepository::class, $repository);
        $this->repository = $repository;
        $this->clock = new MockClock('2026-10-31 10:00:00 UTC');
        $this->manager = new ConnectionManager(new SecretCipher(), $this->repository, $this->clock);
        $this->alice = $this->user('alice');
        $this->aliceKey = $this->key();
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
        $users = self::getContainer()->get(UserRepository::class);
        self::assertInstanceOf(UserRepository::class, $users);
        $users->save($user);

        return $user;
    }

    private function key(): DerivedKey
    {
        return new DerivedKey(random_bytes(DerivedKey::LENGTH));
    }

    private function parameters(string $password = 'hunter2-hunter2'): ConnectionParameters
    {
        return new ConnectionParameters('db.internal', 5432, 'shop', 'reader', $password);
    }

    private function create(string $name = 'Production'): DatabaseConnection
    {
        return $this->manager->create($this->alice, $this->aliceKey, $name, Engine::PostgreSql, $this->parameters());
    }

    public function testACreatedConnectionIsStoredWithoutAnyParameterInTheClear(): void
    {
        $connection = $this->create();
        $this->entityManager->clear();

        $row = $this->entityManager->getConnection()->fetchAssociative(
            'SELECT * FROM database_connection WHERE id = ?',
            [$connection->getId()->toRfc4122()],
        );

        self::assertIsArray($row);
        self::assertSame('Production', $row['name']);
        self::assertSame('postgresql', $row['engine']);
        $stored = json_encode($row, \JSON_THROW_ON_ERROR);
        foreach (['db.internal', 'shop', 'reader', 'hunter2'] as $secret) {
            self::assertStringNotContainsString($secret, $stored);
        }
        self::assertIsString($row['encrypted_parameters']);
        self::assertStringStartsWith('v1:', $row['encrypted_parameters']);
    }

    public function testRevealGivesBackTheParametersToTheirOwner(): void
    {
        $connection = $this->create();
        $this->entityManager->clear();

        $parameters = $this->manager->reveal($this->alice, $this->aliceKey, $connection->getId());

        self::assertSame('db.internal', $parameters->host);
        self::assertSame(5432, $parameters->port);
        self::assertSame('shop', $parameters->database);
        self::assertSame('reader', $parameters->username);
        self::assertSame('hunter2-hunter2', $parameters->password());
    }

    public function testAnotherKeyCannotRevealTheParameters(): void
    {
        $connection = $this->create();

        $this->expectException(DecryptionFailedException::class);

        $this->manager->reveal($this->alice, $this->key(), $connection->getId());
    }

    public function testTheEncryptedParametersAreBoundToTheirConnection(): void
    {
        $first = $this->create('First');
        $second = $this->create('Second');
        $second->rotateEncryptedParameters($first->getEncryptedParameters());
        $this->repository->save($second);

        $this->expectException(DecryptionFailedException::class);

        $this->manager->reveal($this->alice, $this->aliceKey, $second->getId());
    }

    public function testTheContextIsDerivedFromTheConnectionIdentifier(): void
    {
        $connection = $this->create();

        self::assertSame('connection:'.$connection->getId()->toRfc4122(), ConnectionSecretContext::forConnection($connection));
        self::assertSame(ConnectionSecretContext::forConnection($connection), ConnectionSecretContext::forId($connection->getId()));
    }

    public function testAnotherUserCannotRevealUpdateOrDelete(): void
    {
        $connection = $this->create();
        $bob = $this->user('bob');
        $bobKey = $this->key();

        foreach ([
            fn () => $this->manager->reveal($bob, $bobKey, $connection->getId()),
            fn () => $this->manager->update($bob, $bobKey, $connection->getId(), 'Hijacked', Engine::MySql, $this->parameters()),
            fn () => $this->manager->delete($bob, $connection->getId()),
        ] as $attempt) {
            try {
                $attempt();
                self::fail('Expected the connection to be invisible.');
            } catch (ConnectionNotFoundException) {
            }
        }

        $this->entityManager->clear();
        $intact = $this->repository->findOwned($this->alice, $connection->getId());
        self::assertNotNull($intact);
        self::assertSame('Production', $intact->getName());
    }

    public function testAnUnknownConnectionIsNotFound(): void
    {
        $this->expectException(ConnectionNotFoundException::class);

        $this->manager->reveal($this->alice, $this->aliceKey, Uuid::v7());
    }

    public function testListingShowsOnlyTheOwnersConnectionsByName(): void
    {
        $this->create('Staging');
        $this->create('Analytics');
        $bob = $this->user('bob');
        $this->manager->create($bob, $this->key(), 'Bob only', Engine::MySql, $this->parameters());

        $names = array_map(static fn (DatabaseConnection $c): string => $c->getName(), $this->manager->list($this->alice));

        self::assertSame(['Analytics', 'Staging'], $names);
    }

    public function testUpdateReplacesNameEngineAndParameters(): void
    {
        $connection = $this->create();
        $this->clock->sleep(3600);

        $this->manager->update(
            $this->alice,
            $this->aliceKey,
            $connection->getId(),
            'Staging',
            Engine::MariaDb,
            new ConnectionParameters('other.internal', 3306, 'blog', 'writer', 'another-secret'),
        );
        $this->entityManager->clear();

        $found = $this->repository->findOwned($this->alice, $connection->getId());
        self::assertNotNull($found);
        self::assertSame('Staging', $found->getName());
        self::assertSame(Engine::MariaDb, $found->getEngine());
        self::assertSame('2026-10-31T10:00:00+00:00', $found->getCreatedAt()->format(\DATE_ATOM));
        self::assertSame('2026-10-31T11:00:00+00:00', $found->getUpdatedAt()->format(\DATE_ATOM));
        $parameters = $this->manager->reveal($this->alice, $this->aliceKey, $connection->getId());
        self::assertSame('other.internal', $parameters->host);
        self::assertSame('another-secret', $parameters->password());
    }

    public function testUpdateUsesAFreshNonceEvenForIdenticalParameters(): void
    {
        $connection = $this->create();
        $before = $connection->getEncryptedParameters();

        $this->manager->update($this->alice, $this->aliceKey, $connection->getId(), 'Production', Engine::PostgreSql, $this->parameters());

        self::assertNotSame($before, $connection->getEncryptedParameters());
    }

    public function testDeleteRemovesTheConnection(): void
    {
        $connection = $this->create();
        $id = $connection->getId();

        $this->manager->delete($this->alice, $id);
        $this->entityManager->clear();

        self::assertNull($this->repository->findOwned($this->alice, $id));
    }

    public function testADuplicateNameIsRefusedAtCreation(): void
    {
        $this->create('Production');

        $this->expectException(ConnectionNameAlreadyUsedException::class);

        $this->create('Production');
    }

    public function testRenamingToAnExistingNameIsRefused(): void
    {
        $this->create('Production');
        $other = $this->create('Staging');

        $this->expectException(ConnectionNameAlreadyUsedException::class);

        $this->manager->update($this->alice, $this->aliceKey, $other->getId(), 'Production', Engine::PostgreSql, $this->parameters());
    }

    public function testAnInvalidNameStoresNothing(): void
    {
        try {
            $this->create('');
            self::fail('Expected a refusal.');
        } catch (InvalidDatabaseConnectionException) {
            self::assertSame([], $this->repository->findAllOwnedBy($this->alice));
        }
    }
}
