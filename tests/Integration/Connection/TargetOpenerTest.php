<?php

declare(strict_types=1);

namespace App\Tests\Integration\Connection;

use App\Audit\AuditedTargetHosts;
use App\Audit\AuditedTunnels;
use App\Audit\AuditLog;
use App\Audit\RequestAuditContext;
use App\Connection\ConnectionManager;
use App\Connection\Exception\ConnectionNotFoundException;
use App\Connection\HostResolver;
use App\Connection\TargetHostPolicy;
use App\Connection\TargetOpener;
use App\Core\Connection\ConnectionParameters;
use App\Core\Connection\Engine;
use App\Core\Connection\SshJump;
use App\Core\Connection\Tunnel\AllowedBastionTunnelOpener;
use App\Core\Connection\Tunnel\BastionAllowList;
use App\Core\Driver\Driver;
use App\Core\Driver\DriverRegistry;
use App\Core\Driver\TargetConnection;
use App\Tests\Core\Connection\Tunnel\RecordingTunnelOpener;
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

    private function opener(?AuditedTargetHosts $hosts = null, ?RecordingTunnelOpener $tunnels = null, ?DriverRegistry $drivers = null): TargetOpener
    {
        $audit = self::getContainer()->get(AuditLog::class);
        self::assertInstanceOf(AuditLog::class, $audit);

        return new TargetOpener(
            $this->manager,
            $drivers ?? $this->drivers,
            $hosts ?? $this->hostPolicy(),
            new AuditedTunnels(new AllowedBastionTunnelOpener($tunnels ?? new RecordingTunnelOpener(), BastionAllowList::fromString('bastion.example')), $audit),
            new RequestAuditContext(new RequestStack()),
        );
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
            $target = ($this->opener())->open($this->alice, $this->key, $saved->getId());
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

        $target = ($this->opener($hosts))->open($this->alice, $this->key, $saved->getId());

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

        $target = ($this->opener())->open($this->alice, $this->key, $saved->getId(), $other);

        $names = array_map(static fn ($table): string => $table->name, $target->introspector()->listTables());
        self::assertNotContains('categories', $names);
        self::assertNotSame([], $names);
        $target->close();
    }

    public function testAnInvalidDatabaseNameIsRefusedBeforeConnecting(): void
    {
        $saved = $this->manager->create($this->alice, $this->key, 'Target', TargetDatabase::engine('TARGET_POSTGRES_URL'), TargetDatabase::parameters('TARGET_POSTGRES_URL'));

        $this->expectException(\App\Core\Connection\Exception\InvalidConnectionParametersException::class);

        ($this->opener())->open($this->alice, $this->key, $saved->getId(), "bad\x00name");
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

        ($this->opener())->open($bob, $this->key, $saved->getId());
    }

    public function testTheWrongKeyCannotOpenIt(): void
    {
        $saved = $this->manager->create($this->alice, $this->key, 'Target', Engine::PostgreSql, TargetDatabase::parameters('TARGET_POSTGRES_URL'));

        $this->expectException(DecryptionFailedException::class);

        ($this->opener())->open($this->alice, new DerivedKey(random_bytes(DerivedKey::LENGTH)), $saved->getId());
    }

    public function testASavedWrongPasswordIsReportedAsAFailedConnection(): void
    {
        $parameters = TargetDatabase::parameters('TARGET_POSTGRES_URL')->withPassword('wrong-secret-value');
        $saved = $this->manager->create($this->alice, $this->key, 'Target', Engine::PostgreSql, $parameters);

        $this->expectException(TargetConnectionFailedException::class);

        ($this->opener())->open($this->alice, $this->key, $saved->getId());
    }

    public function testAnEngineWithoutDriverIsRefused(): void
    {
        $saved = $this->manager->create($this->alice, $this->key, 'Target', Engine::PostgreSql, TargetDatabase::parameters('TARGET_POSTGRES_URL'));

        $this->expectException(UnsupportedEngineException::class);

        ($this->opener(drivers: new DriverRegistry([])))->open($this->alice, $this->key, $saved->getId());
    }

    public function testOpensAnotherSchemaOfAPostgreSqlDatabase(): void
    {
        $saved = $this->manager->create($this->alice, $this->key, 'Target', TargetDatabase::engine('TARGET_POSTGRES_URL'), TargetDatabase::parameters('TARGET_POSTGRES_URL'));

        $target = ($this->opener())->open($this->alice, $this->key, $saved->getId(), null, 'information_schema');

        $names = array_map(static fn ($table): string => $table->name, $target->introspector()->listTables());
        self::assertContains('columns', $names);
        self::assertNotContains('categories', $names);
        $target->close();
    }

    public function testTheDefaultDatabaseIsNullWhenTheConnectionNamesNone(): void
    {
        $withDatabase = $this->manager->create($this->alice, $this->key, 'A', TargetDatabase::engine('TARGET_POSTGRES_URL'), TargetDatabase::parameters('TARGET_POSTGRES_URL'));
        $without = $this->manager->create($this->alice, $this->key, 'B', TargetDatabase::engine('TARGET_POSTGRES_URL'), TargetDatabase::parameters('TARGET_POSTGRES_URL')->withDatabase(''));
        $opener = $this->opener();

        self::assertSame('shop', $opener->defaultDatabase($this->alice, $this->key, $withDatabase->getId()));
        self::assertNull($opener->defaultDatabase($this->alice, $this->key, $without->getId()));
    }

    private const KEY = "-----BEGIN OPENSSH PRIVATE KEY-----\nAAAAfaux\n-----END OPENSSH PRIVATE KEY-----\n";

    private function throughJump(string $host = 'db.behind', string $bastion = 'bastion.example', Engine $engine = Engine::PostgreSql, ?string $fingerprint = RecordingTunnelOpener::FINGERPRINT, int $port = 5432): \App\Entity\DatabaseConnection
    {
        $parameters = new ConnectionParameters($host, $port, 'shop', 'reader', 'secret', transport: new SshJump($bastion, 22, 'tunnel', self::KEY, $fingerprint));

        return $this->manager->create($this->alice, $this->key, 'Via bastion', $engine, $parameters);
    }

    public function testAJumpIsAskedForTheTargetBehindItAndTheDriverGetsOnlyTheSocket(): void
    {
        $saved = $this->throughJump();
        $tunnels = new RecordingTunnelOpener();
        $drivers = new class implements Driver {
            public ?ConnectionParameters $received = null;

            public function engines(): array
            {
                return [Engine::PostgreSql];
            }

            public function connect(Engine $engine, ConnectionParameters $parameters): TargetConnection
            {
                $this->received = $parameters;

                throw TargetConnectionFailedException::create(new \RuntimeException('stop here'));
            }
        };

        try {
            $this->opener(tunnels: $tunnels, drivers: new DriverRegistry([$drivers]))->open($this->alice, $this->key, $saved->getId());
            self::fail('the fake driver refuses');
        } catch (TargetConnectionFailedException) {
        }

        self::assertSame(1, $tunnels->opened);
        self::assertSame('db.behind', $tunnels->last?->targetHost);
        self::assertSame(5432, $tunnels->last->targetPort);
        self::assertSame('.s.PGSQL.5432', $tunnels->last->socketName);
        self::assertSame('bastion.example', $tunnels->last->jump->host);
        self::assertNotNull($drivers->received);
        self::assertSame('/run/tunnels/abc/tunnel.sock', $drivers->received->socketPath());
        self::assertSame('db.behind', $drivers->received->host);
    }

    public function testAMemberCannotAimTheServerItselfBehindAJump(): void
    {
        $saved = $this->throughJump('127.0.0.1');
        $tunnels = new RecordingTunnelOpener();

        try {
            $this->opener(tunnels: $tunnels)->open($this->alice, $this->key, $saved->getId());
            self::fail('refused expected');
        } catch (\App\Connection\Exception\ForbiddenTargetHostException $refused) {
            self::assertTrue($refused->behindJump);
        }

        self::assertSame(0, $tunnels->opened);
    }

    public function testAnUnlistedBastionIsRefusedAndNeverOpened(): void
    {
        $saved = $this->throughJump(bastion: 'other.example');
        $tunnels = new RecordingTunnelOpener();

        $this->expectException(\App\Core\Connection\Exception\ForbiddenBastionException::class);

        $this->opener(tunnels: $tunnels)->open($this->alice, $this->key, $saved->getId());
    }

    public function testAFailedTunnelIsPropagatedWithItsReasonOnly(): void
    {
        $saved = $this->throughJump();
        $tunnels = new RecordingTunnelOpener();
        $tunnels->failure = new \App\Core\Connection\Tunnel\TunnelFailedException(\App\Core\Connection\Tunnel\TunnelFailedException::HOST_KEY_CHANGED);

        try {
            $this->opener(tunnels: $tunnels)->open($this->alice, $this->key, $saved->getId());
            self::fail('failure expected');
        } catch (\App\Core\Connection\Tunnel\TunnelFailedException $failure) {
            self::assertSame('host_key_changed', $failure->reason);
        }
    }

    /**
     * @return iterable<string, array{Engine}>
     */
    public static function enginesWithoutSocket(): iterable
    {
        yield 'ClickHouse' => [Engine::ClickHouse];
        yield 'Redis' => [Engine::Redis];
    }

    #[DataProvider('enginesWithoutSocket')]
    public function testAnEngineThatCannotUseASocketIsNotOpenedThroughAJump(Engine $engine): void
    {
        $saved = $this->throughJump(engine: $engine, port: 6379);
        $tunnels = new RecordingTunnelOpener();

        try {
            $this->opener(tunnels: $tunnels)->open($this->alice, $this->key, $saved->getId());
            self::fail('unsupported expected');
        } catch (UnsupportedOperationException) {
        }

        self::assertSame(0, $tunnels->opened);
    }

    public function testProbingParametersWithAJumpGoesThroughTheTunnelToo(): void
    {
        $tunnels = new RecordingTunnelOpener();
        $parameters = new ConnectionParameters('db.behind', 5432, 'shop', 'reader', 'secret', transport: new SshJump('bastion.example', 22, 'tunnel', self::KEY, RecordingTunnelOpener::FINGERPRINT));

        try {
            $this->opener(tunnels: $tunnels)->connect($this->alice, Engine::PostgreSql, $parameters)->close();
        } catch (TargetConnectionFailedException) {
            // Le faux tunnel ne mène nulle part : seul compte qu'il ait été demandé.
        }

        self::assertSame(1, $tunnels->opened);
    }
}
