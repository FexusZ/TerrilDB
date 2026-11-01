<?php

declare(strict_types=1);

namespace App\Tests\Integration\Connection;

use App\Connection\ConnectionManager;
use App\Connection\TargetOpener;
use App\Core\Connection\Engine;
use App\Core\Driver\DriverRegistry;
use App\Core\Driver\Exception\TargetConnectionFailedException;
use App\Core\Driver\TargetConnection;
use App\Core\Guard\GuardReason;
use App\Core\Guard\StatementGuard;
use App\Core\Guard\StatementKind;
use App\Core\Query\Exception\QueryFailedException;
use App\Core\Query\Exception\QueryNeedsConfirmationException;
use App\Core\Query\Exception\QueryRefusedException;
use App\Core\Query\QueryMode;
use App\Core\Query\QueryOptions;
use App\Core\Query\QueryService;
use App\Crypto\DerivedKey;
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

/**
 * Du compte de l'utilisateur à l'instruction exécutée sur la vraie cible, en passant par la connexion chiffrée.
 */
#[Group('integration')]
final class QueryEndToEndTest extends DatabaseTestCase
{
    private QueryService $queries;
    private ?TargetConnection $target = null;

    /**
     * @return iterable<string, array{string}>
     */
    public static function targets(): iterable
    {
        yield from TargetDatabase::all();
    }

    private function open(string $envVar): TargetConnection
    {
        $repository = self::getContainer()->get(DatabaseConnectionRepository::class);
        self::assertInstanceOf(DatabaseConnectionRepository::class, $repository);
        $users = self::getContainer()->get(UserRepository::class);
        self::assertInstanceOf(UserRepository::class, $users);
        $drivers = self::getContainer()->get(DriverRegistry::class);
        self::assertInstanceOf(DriverRegistry::class, $drivers);

        $user = new User(
            'alice@example.test',
            'alice',
            (new AuthPasswordHasher(opsLimit: 3, memLimit: 16384))->hash('correct horse battery'),
            (new KeyDerivation(opsLimit: 1, memLimit: 8192))->newParameters(),
            new \DateTimeImmutable('2026-10-29T12:00:00Z'),
        );
        $users->save($user);
        $key = new DerivedKey(random_bytes(DerivedKey::LENGTH));
        $manager = new ConnectionManager(new SecretCipher(), $repository, new MockClock('2026-11-01 10:00:00 UTC'));
        $saved = $manager->create($user, $key, 'Target', TargetDatabase::engine($envVar), TargetDatabase::parameters($envVar));

        try {
            $this->target = (new TargetOpener($manager, $drivers))->open($user, $key, $saved->getId());
        } catch (TargetConnectionFailedException) {
            self::markTestSkipped(\sprintf('%s is unreachable (docker compose --profile targets up -d --wait).', $envVar));
        }
        $this->queries = new QueryService(new StatementGuard());

        return $this->target;
    }

    protected function tearDown(): void
    {
        $this->target?->close();
        parent::tearDown();
    }

    private function categoryCount(TargetConnection $target): int
    {
        $rows = $this->queries->execute($target, 'select count(*) from categories')->rows;

        return (int) $rows[0][0];
    }

    #[DataProvider('targets')]
    public function testReadsRunAgainstTheRealTarget(string $envVar): void
    {
        $target = $this->open($envVar);

        $result = $this->queries->execute($target, 'select id, name from categories order by id', new QueryOptions(QueryMode::ReadOnly, 5_000, 5));

        self::assertSame(['id', 'name'], $result->columns);
        self::assertLessThanOrEqual(5, $result->rowCount());
        self::assertGreaterThan(0, $result->rowCount());
    }

    #[DataProvider('targets')]
    public function testWritesAndHiddenSecondStatementsAreRefusedBeforeReachingTheTarget(string $envVar): void
    {
        $target = $this->open($envVar);
        $before = $this->categoryCount($target);

        foreach ([
            "insert into categories (name) values ('sneaky')",
            'delete from categories',
            'drop table categories',
            'select 1; drop table categories',
        ] as $sql) {
            try {
                $this->queries->execute($target, $sql);
                self::fail('Expected a refusal for: '.$sql);
            } catch (QueryRefusedException) {
            }
        }

        self::assertSame($before, $this->categoryCount($target));
    }

    #[DataProvider('targets')]
    public function testACommentThatTheEngineExecutesIsSeenAsCode(string $envVar): void
    {
        $target = $this->open($envVar);
        $before = $this->categoryCount($target);
        $sql = 'select 1 /*! ; drop table categories */';

        if (\in_array($target->engine, [Engine::MySql, Engine::MariaDb], true)) {
            $this->expectException(QueryRefusedException::class);
            $this->queries->execute($target, $sql);
        } else {
            // PostgreSQL ne connaît pas ce commentaire exécutable : c'est un commentaire, la requête est inoffensive.
            self::assertSame([[1]], $this->queries->execute($target, $sql)->rows);
            self::assertSame($before, $this->categoryCount($target));
        }
    }

    #[DataProvider('targets')]
    public function testEvenWithoutTheGuardTheEngineKeepsTheTargetReadOnly(string $envVar): void
    {
        $target = $this->open($envVar);
        $before = $this->categoryCount($target);

        foreach ([
            ["insert into categories (name) values ('sneaky')", StatementKind::Write],
            ['delete from categories', StatementKind::Write],
            ['create table sneaky (a int)', StatementKind::Schema],
            ['drop table categories', StatementKind::Schema],
            ['truncate table customers', StatementKind::Schema],
        ] as [$sql, $kind]) {
            try {
                $target->runner()->run($sql, $kind, new QueryOptions(QueryMode::ReadOnly));
                self::fail('Expected the engine to refuse: '.$sql);
            } catch (QueryFailedException) {
            }
        }

        self::assertSame($before, $this->categoryCount($target));
        self::assertGreaterThan(0, $this->queries->execute($target, 'select count(*) from customers')->rows[0][0]);
    }

    #[DataProvider('targets')]
    public function testADestructiveStatementWaitsForConfirmationAndNothingRuns(string $envVar): void
    {
        $target = $this->open($envVar);
        $before = $this->categoryCount($target);

        try {
            $this->queries->execute($target, 'delete from categories', new QueryOptions(QueryMode::ReadWrite));
            self::fail('Expected a confirmation request.');
        } catch (QueryNeedsConfirmationException $e) {
            self::assertSame(GuardReason::DestructiveWithoutWhere, $e->reason);
        }

        self::assertSame($before, $this->categoryCount($target));
    }
}
