<?php

declare(strict_types=1);

namespace App\Tests\Integration\Connection;

use App\Audit\AuditedTargetHosts;
use App\Connection\ConnectionManager;
use App\Connection\Exception\ConnectionNotFoundException;
use App\Connection\HostResolver;
use App\Connection\TargetHostPolicy;
use App\Connection\TargetOpener;
use App\Core\Connection\ConnectionParameters;
use App\Core\Connection\Engine;
use App\Core\Connection\SshJump;
use App\Core\Driver\DriverRegistry;
use App\Core\Driver\Exception\TargetConnectionFailedException;
use App\Core\Driver\Exception\UnsupportedEngineException;
use App\Core\Driver\Exception\UnsupportedOperationException;
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
use Symfony\Component\HttpFoundation\RequestStack;

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

    private function hostPolicy(): AuditedTargetHosts
    {
        $policy = self::getContainer()->get(AuditedTargetHosts::class);
        self::assertInstanceOf(AuditedTargetHosts::class, $policy);

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

    public function testTheDriverGetsTheAddressThatWasCheckedNotTheNameAgain(): void
    {
        $real = TargetDatabase::parameters('TARGET_POSTGRES_URL');
        $address = gethostbyname($real->host);
        $saved = $this->manager->create($this->alice, $this->key, 'Flip', TargetDatabase::engine('TARGET_POSTGRES_URL'), new ConnectionParameters('flip.example', $real->port, $real->database, $real->username, $real->password()));
        $resolver = new class($address) implements HostResolver {
            public int $calls = 0;

            public function __construct(private string $address)
            {
            }

            public function resolve(string $host): array
            {
                ++$this->calls;

                // Première réponse : la vraie base ; toute réponse suivante : la boucle locale.
                return 1 === $this->calls ? [$this->address] : ['127.0.0.1'];
            }
        };
        $audit = self::getContainer()->get(\App\Audit\AuditLog::class);
        self::assertInstanceOf(\App\Audit\AuditLog::class, $audit);
        $hosts = new AuditedTargetHosts(new TargetHostPolicy($resolver, null), $audit, new RequestStack());

        $target = (new TargetOpener($this->manager, $this->drivers, $hosts))->open($this->alice, $this->key, $saved->getId());

        self::assertSame(1, $resolver->calls);
        self::assertCount(7, $target->introspector()->listTables());
        $target->close();
    }

    /**
     * @return iterable<string, array{string, string}> cible, base voisine à ouvrir à la place de « shop »
     */
    public static function neighbours(): iterable
    {
        yield 'MySQL' => ['TARGET_MYSQL_URL', 'information_schema'];
        yield 'MariaDB' => ['TARGET_MARIADB_URL', 'information_schema'];
        yield 'ClickHouse' => ['TARGET_CLICKHOUSE_URL', 'system'];
    }

    #[DataProvider('neighbours')]
    public function testOpensAnotherDatabaseOfTheSameConnection(string $envVar, string $other): void
    {
        $saved = $this->manager->create($this->alice, $this->key, 'Target', TargetDatabase::engine($envVar), TargetDatabase::parameters($envVar));

        $target = (new TargetOpener($this->manager, $this->drivers, $this->hostPolicy()))->open($this->alice, $this->key, $saved->getId(), $other);

        $names = array_map(static fn ($table): string => $table->name, $target->introspector()->listTables());
        self::assertNotContains('categories', $names);
        self::assertNotSame([], $names);
        $target->close();
    }

    public function testAnInvalidDatabaseNameIsRefusedBeforeConnecting(): void
    {
        $saved = $this->manager->create($this->alice, $this->key, 'Target', TargetDatabase::engine('TARGET_POSTGRES_URL'), TargetDatabase::parameters('TARGET_POSTGRES_URL'));

        $this->expectException(\App\Core\Connection\Exception\InvalidConnectionParametersException::class);

        (new TargetOpener($this->manager, $this->drivers, $this->hostPolicy()))->open($this->alice, $this->key, $saved->getId(), "bad\x00name");
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

    public function testOpensAnotherSchemaOfAPostgreSqlDatabase(): void
    {
        $saved = $this->manager->create($this->alice, $this->key, 'Target', TargetDatabase::engine('TARGET_POSTGRES_URL'), TargetDatabase::parameters('TARGET_POSTGRES_URL'));

        $target = (new TargetOpener($this->manager, $this->drivers, $this->hostPolicy()))->open($this->alice, $this->key, $saved->getId(), null, 'information_schema');

        $names = array_map(static fn ($table): string => $table->name, $target->introspector()->listTables());
        self::assertContains('columns', $names);
        self::assertNotContains('categories', $names);
        $target->close();
    }

    public function testTheDefaultDatabaseIsNullWhenTheConnectionNamesNone(): void
    {
        $withDatabase = $this->manager->create($this->alice, $this->key, 'A', TargetDatabase::engine('TARGET_POSTGRES_URL'), TargetDatabase::parameters('TARGET_POSTGRES_URL'));
        $without = $this->manager->create($this->alice, $this->key, 'B', TargetDatabase::engine('TARGET_POSTGRES_URL'), TargetDatabase::parameters('TARGET_POSTGRES_URL')->withDatabase(''));
        $opener = new TargetOpener($this->manager, $this->drivers, $this->hostPolicy());

        self::assertSame('shop', $opener->defaultDatabase($this->alice, $this->key, $withDatabase->getId()));
        self::assertNull($opener->defaultDatabase($this->alice, $this->key, $without->getId()));
    }

    public function testAConnectionWithAJumpIsNotOpenedYet(): void
    {
        $key = "-----BEGIN OPENSSH PRIVATE KEY-----\nAAAAfaux\n-----END OPENSSH PRIVATE KEY-----\n";
        $parameters = new ConnectionParameters('db.example', 5432, 'shop', 'reader', 'secret', transport: new SshJump('93.184.216.34', 22, 'tunnel', $key));
        $saved = $this->manager->create($this->alice, $this->key, 'Via bastion', Engine::PostgreSql, $parameters);
        $opener = new TargetOpener($this->manager, $this->drivers, $this->hostPolicy());

        $this->expectException(UnsupportedOperationException::class);

        $opener->open($this->alice, $this->key, $saved->getId());
    }
}
