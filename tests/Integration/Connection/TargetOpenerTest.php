<?php

declare(strict_types=1);

namespace App\Tests\Integration\Connection;

use App\Connection\ConnectionManager;
use App\Connection\Exception\ConnectionNotFoundException;
use App\Connection\TargetHostPolicy;
use App\Connection\TargetOpener;
use App\Core\Connection\Engine;
use App\Core\Driver\DriverRegistry;
use App\Core\Driver\Exception\TargetConnectionFailedException;
use App\Core\Driver\Exception\UnsupportedEngineException;
use App\Crypto\DerivedKey;
use App\Crypto\Exception\DecryptionFailedException;
use App\Crypto\KeyDerivation;
use App\Crypto\SecretCipher;
use App\Entity\User;
use App\Repository\DatabaseConnectionRepository;
use App\Repository\UserRepository;
use App\Security\AuthPasswordHasher;
use App\Tests\Integration\Driver\TargetDatabase;
use App\Tests\Integration\Persistence\DatabaseTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Clock\MockClock;

#[Group('integration')]
final class TargetOpenerTest extends DatabaseTestCase
{
    private ConnectionManager $manager;
    private DriverRegistry $drivers;
    private User $alice;
    private DerivedKey $key;

    protected function setUp(): void
    {
        parent::setUp();

        $repository = self::getContainer()->get(DatabaseConnectionRepository::class);
        self::assertInstanceOf(DatabaseConnectionRepository::class, $repository);
        $this->manager = new ConnectionManager(new SecretCipher(), $repository, new MockClock('2026-11-01 10:00:00 UTC'));
        $drivers = self::getContainer()->get(DriverRegistry::class);
        self::assertInstanceOf(DriverRegistry::class, $drivers);
        $this->drivers = $drivers;
        $this->alice = $this->user('alice');
        $this->key = new DerivedKey(random_bytes(DerivedKey::LENGTH));
    }

    private function hostPolicy(): TargetHostPolicy
    {
        $policy = self::getContainer()->get(TargetHostPolicy::class);
        self::assertInstanceOf(TargetHostPolicy::class, $policy);

        return $policy;
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

    /**
     * @return iterable<string, array{string}>
     */
    public static function targets(): iterable
    {
        yield from TargetDatabase::all();
    }

    #[DataProvider('targets')]
    public function testOpensTheSavedTargetAndIntrospectsIt(string $envVar): void
    {
        $engine = TargetDatabase::engine($envVar);
        $saved = $this->manager->create($this->alice, $this->key, 'Target', $engine, TargetDatabase::parameters($envVar));

        try {
            $target = (new TargetOpener($this->manager, $this->drivers, $this->hostPolicy()))->open($this->alice, $this->key, $saved->getId());
        } catch (TargetConnectionFailedException) {
            self::markTestSkipped(\sprintf('%s is unreachable (docker compose --profile targets up -d --wait).', $envVar));
        }

        self::assertSame($engine, $target->engine);
        self::assertCount(7, $target->introspector()->listTables());
        $target->close();
    }

    public function testTheContainerKnowsADriverForEveryEngine(): void
    {
        foreach (Engine::cases() as $engine) {
            self::assertContains($engine, $this->drivers->for($engine)->engines());
        }
    }

    public function testAnotherUserCannotOpenIt(): void
    {
        $saved = $this->manager->create($this->alice, $this->key, 'Target', Engine::PostgreSql, TargetDatabase::parameters('TARGET_POSTGRES_URL'));
        $bob = $this->user('bob');

        $this->expectException(ConnectionNotFoundException::class);

        (new TargetOpener($this->manager, $this->drivers, $this->hostPolicy()))->open($bob, $this->key, $saved->getId());
    }

    public function testTheWrongKeyCannotOpenIt(): void
    {
        $saved = $this->manager->create($this->alice, $this->key, 'Target', Engine::PostgreSql, TargetDatabase::parameters('TARGET_POSTGRES_URL'));

        $this->expectException(DecryptionFailedException::class);

        (new TargetOpener($this->manager, $this->drivers, $this->hostPolicy()))->open($this->alice, new DerivedKey(random_bytes(DerivedKey::LENGTH)), $saved->getId());
    }

    public function testASavedWrongPasswordIsReportedAsAFailedConnection(): void
    {
        $parameters = TargetDatabase::parameters('TARGET_POSTGRES_URL')->withPassword('wrong-secret-value');
        $saved = $this->manager->create($this->alice, $this->key, 'Target', Engine::PostgreSql, $parameters);

        $this->expectException(TargetConnectionFailedException::class);

        (new TargetOpener($this->manager, $this->drivers, $this->hostPolicy()))->open($this->alice, $this->key, $saved->getId());
    }

    public function testAnEngineWithoutDriverIsRefused(): void
    {
        $saved = $this->manager->create($this->alice, $this->key, 'Target', Engine::PostgreSql, TargetDatabase::parameters('TARGET_POSTGRES_URL'));

        $this->expectException(UnsupportedEngineException::class);

        (new TargetOpener($this->manager, new DriverRegistry([]), $this->hostPolicy()))->open($this->alice, $this->key, $saved->getId());
    }
}
