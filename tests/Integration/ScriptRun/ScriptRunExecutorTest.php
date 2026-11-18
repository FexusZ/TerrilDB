<?php

declare(strict_types=1);

namespace App\Tests\Integration\ScriptRun;

use App\Core\Connection\ConnectionParameters;
use App\Core\Connection\Engine;
use App\Core\Driver\DriverRegistry;
use App\Core\Guard\ScriptAnalyzer;
use App\Core\Query\Script\ScriptService;
use App\Core\Query\WritePermissions;
use App\Crypto\KeyDerivation;
use App\Entity\ScriptRun;
use App\Entity\ScriptRunStatus;
use App\Entity\User;
use App\Repository\ScriptRunRepository;
use App\Repository\UserRepository;
use App\ScriptRun\ScriptRunExecutor;
use App\ScriptRun\ScriptRunRequest;
use App\Tests\Integration\Driver\TargetDatabase;
use App\Tests\Integration\Persistence\DatabaseTestCase;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Uid\Uuid;

/**
 * Une exécution détachée de bout en bout (#169, PR 3) : le message reçu, la cible ouverte sans clé d'utilisateur, le suivi écrit dans
 * la base interne instruction par instruction, le rapport final. Sur des tables jetables des vraies cibles.
 */
#[Group('integration')]
final class ScriptRunExecutorTest extends DatabaseTestCase
{
    private const TEXT_HASH = 'a3f1c0de9b2e4d5f6a7b8c9d0e1f2a3b4c5d6e7f8091a2b3c4d5e6f708192a3b';

    private ScriptRunRepository $runs;
    private ScriptRunExecutor $executor;
    private MockClock $clock;
    private ?Connection $raw = null;
    /** @var list<string> */
    private array $tables = [];

    protected function setUp(): void
    {
        parent::setUp();
        $runs = self::getContainer()->get(ScriptRunRepository::class);
        self::assertInstanceOf(ScriptRunRepository::class, $runs);
        $this->runs = $runs;
        $drivers = self::getContainer()->get(DriverRegistry::class);
        self::assertInstanceOf(DriverRegistry::class, $drivers);
        $this->clock = new MockClock('2026-11-19 01:00:00 UTC');
        $this->executor = new ScriptRunExecutor($this->runs, $drivers, new ScriptService(new ScriptAnalyzer()), $this->clock);
    }

    protected function tearDown(): void
    {
        foreach ($this->tables as $table) {
            try {
                $this->raw?->executeStatement('DROP TABLE IF EXISTS '.$table);
            } catch (\Throwable) {
            }
        }
        $this->raw?->close();
        parent::tearDown();
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function targets(): iterable
    {
        yield 'PostgreSQL' => ['TARGET_POSTGRES_URL'];
        yield 'MySQL' => ['TARGET_MYSQL_URL'];
        yield 'MariaDB' => ['TARGET_MARIADB_URL'];
    }

    private function table(string $envVar): string
    {
        $this->raw = (new \App\Driver\DbalConnector())->connect(TargetDatabase::engine($envVar), TargetDatabase::parameters($envVar));
        $table = 'tdb_run_'.bin2hex(random_bytes(4));
        $this->tables[] = $table;
        $this->raw->executeStatement(\sprintf('CREATE TABLE %s (id int NOT NULL PRIMARY KEY, v varchar(20))', $table));

        return $table;
    }

    /** @return list<int> */
    private function ids(string $table): array
    {
        \assert(null !== $this->raw);

        return array_map(static fn (mixed $v): int => is_numeric($v) ? (int) $v : 0, $this->raw->fetchFirstColumn(\sprintf('SELECT id FROM %s ORDER BY id', $table)));
    }

    private function owner(): User
    {
        $users = self::getContainer()->get(UserRepository::class);
        self::assertInstanceOf(UserRepository::class, $users);
        $user = new User('dave@example.test', 'dave', '$argon2id$v=19$m=16384,t=3,p=1$c29tZXNhbHQ$aGFzaGhhc2hoYXNoaGFzaA', (new KeyDerivation(opsLimit: 1, memLimit: 8192))->newParameters(), new \DateTimeImmutable('2026-11-01T10:00:00Z'));
        $users->save($user);

        return $user;
    }

    private function queued(int $total, bool $dryRun = false): ScriptRun
    {
        $run = new ScriptRun(Uuid::v7(), $this->owner(), Uuid::v7(), self::TEXT_HASH, $total, $dryRun, \DateTimeImmutable::createFromInterface($this->clock->now()));
        $this->runs->save($run);

        return $run;
    }

    private function request(ScriptRun $run, string $envVar, string $script, bool $dryRun = false, ?ConnectionParameters $parameters = null): ScriptRunRequest
    {
        return new ScriptRunRequest($run->getId(), TargetDatabase::engine($envVar), $parameters ?? TargetDatabase::parameters($envVar), $script, WritePermissions::all(), true, $dryRun, 60_000);
    }

    private function reload(ScriptRun $run): ScriptRun
    {
        $this->entityManager->clear();
        $found = $this->runs->find($run->getId());
        self::assertNotNull($found);

        return $found;
    }

    #[DataProvider('targets')]
    public function testASuccessfulScriptIsFollowedAndCommitted(string $envVar): void
    {
        $table = $this->table($envVar);
        $run = $this->queued(3);

        $this->executor->run($this->request($run, $envVar, \sprintf("INSERT INTO %1\$s VALUES (1, 'a'); INSERT INTO %1\$s VALUES (2, 'b'); SELECT * FROM %1\$s", $table)));

        $done = $this->reload($run);
        self::assertSame(ScriptRunStatus::Committed, $done->getStatus());
        self::assertSame(3, $done->getCompleted());
        self::assertSame(['applied', 'applied', 'applied'], array_map(static fn (array $s): string => $s['status'], $done->getStatements()));
        self::assertSame([1, 1, 2], array_map(static fn (array $s): ?int => $s['affectedRows'], $done->getStatements()), 'écritures : lignes touchées ; lecture : lignes rendues');
        self::assertNotNull($done->getStartedAt());
        self::assertNotNull($done->getFinishedAt());
        self::assertSame([1, 2], $this->ids($table));
    }

    #[DataProvider('targets')]
    public function testAFailingScriptKeepsTheFailureCodeAndTheFinalStatusOfEachStatement(string $envVar): void
    {
        $table = $this->table($envVar);
        \assert(null !== $this->raw);
        $this->raw->executeStatement(\sprintf("INSERT INTO %s VALUES (1, 'x')", $table));
        $run = $this->queued(3);

        $this->executor->run($this->request($run, $envVar, \sprintf("INSERT INTO %1\$s VALUES (2, 'b'); INSERT INTO %1\$s VALUES (1, 'dup'); INSERT INTO %1\$s VALUES (3, 'c');", $table)));

        $done = $this->reload($run);
        self::assertSame(ScriptRunStatus::RolledBack, $done->getStatus());
        self::assertSame(['rolled_back', 'failed', 'not_run'], array_map(static fn (array $s): string => $s['status'], $done->getStatements()));
        $failed = $done->getStatements()[1];
        self::assertMatchesRegularExpression('/^[0-9A-Z]{5}$/', (string) $failed['sqlstate']);
        self::assertSame(2, $done->getCompleted());
        self::assertSame([1], $this->ids($table));
    }

    #[DataProvider('targets')]
    public function testADryRunEndsAsADryRun(string $envVar): void
    {
        $table = $this->table($envVar);
        $run = $this->queued(1, true);

        $this->executor->run($this->request($run, $envVar, \sprintf("INSERT INTO %s VALUES (1, 'a')", $table), dryRun: true));

        self::assertSame(ScriptRunStatus::DryRun, $this->reload($run)->getStatus());
        self::assertSame([], $this->ids($table));
    }

    public function testACancelAskedBeforeTheFirstStatementStopsEverythingAndEndsCancelled(): void
    {
        $table = $this->table('TARGET_POSTGRES_URL');
        $run = $this->queued(2);
        $run->requestCancel();
        $this->runs->save($run);

        $this->executor->run($this->request($run, 'TARGET_POSTGRES_URL', \sprintf("INSERT INTO %1\$s VALUES (1, 'a'); INSERT INTO %1\$s VALUES (2, 'b');", $table)));

        $done = $this->reload($run);
        self::assertSame(ScriptRunStatus::Cancelled, $done->getStatus());
        self::assertSame(['not_run', 'not_run'], array_map(static fn (array $s): string => $s['status'], $done->getStatements()));
        self::assertSame([], $this->ids($table));
    }

    public function testACancelAskedWhileItRunsIsSeenBetweenTwoStatements(): void
    {
        $table = $this->table('TARGET_POSTGRES_URL');
        $run = $this->queued(3);
        $runs = $this->runs;
        $id = $run->getId();
        // Le second client (la route d'annulation) pose la demande pendant l'exécution : on la simule dès la première instruction finie.
        $executor = new ScriptRunExecutor($this->runs, self::driverRegistry(), new ScriptService(new ScriptAnalyzer()), $this->clock, afterStatement: static function () use ($runs, $id): void {
            $runs->requestCancelOf($id);
        });

        $executor->run($this->request($run, 'TARGET_POSTGRES_URL', \sprintf("INSERT INTO %1\$s VALUES (1, 'a'); INSERT INTO %1\$s VALUES (2, 'b'); INSERT INTO %1\$s VALUES (3, 'c');", $table)));

        $done = $this->reload($run);
        self::assertSame(ScriptRunStatus::Cancelled, $done->getStatus());
        self::assertSame(['rolled_back', 'not_run', 'not_run'], array_map(static fn (array $s): string => $s['status'], $done->getStatements()));
        self::assertSame([], $this->ids($table));
    }

    public function testATargetThatCannotBeReachedEndsTheRunFailedWithoutAnException(): void
    {
        $run = $this->queued(1);
        $parameters = new ConnectionParameters('127.0.0.1', 1, 'nope', 'nobody', 'wrong-password-value');

        $this->executor->run(new ScriptRunRequest($run->getId(), Engine::PostgreSql, $parameters, 'SELECT 1', WritePermissions::all(), true, false, 5_000));

        $done = $this->reload($run);
        self::assertSame(ScriptRunStatus::Failed, $done->getStatus());
        self::assertSame([], $done->getStatements());
        self::assertNotNull($done->getFinishedAt());
    }

    public function testAScriptTheGuardRefusesEndsTheRunFailed(): void
    {
        $run = $this->queued(1);

        $this->executor->run(new ScriptRunRequest($run->getId(), Engine::PostgreSql, TargetDatabase::parameters('TARGET_POSTGRES_URL'), 'COPY t FROM PROGRAM \'x\'', new WritePermissions(false, false), true, false, 5_000));

        self::assertSame(ScriptRunStatus::Failed, $this->reload($run)->getStatus());
    }

    public function testARunThatIsNotQueuedIsLeftAlone(): void
    {
        $table = $this->table('TARGET_POSTGRES_URL');
        $run = $this->queued(1);
        $run->start(\DateTimeImmutable::createFromInterface($this->clock->now()));
        $run->finish(ScriptRunStatus::Committed, \DateTimeImmutable::createFromInterface($this->clock->now()));
        $this->runs->save($run);

        $this->executor->run($this->request($run, 'TARGET_POSTGRES_URL', \sprintf("INSERT INTO %s VALUES (1, 'a')", $table)));

        self::assertSame([], $this->ids($table));
    }

    public function testAnUnknownRunIsIgnored(): void
    {
        $table = $this->table('TARGET_POSTGRES_URL');

        $this->executor->run(new ScriptRunRequest(Uuid::v7(), Engine::PostgreSql, TargetDatabase::parameters('TARGET_POSTGRES_URL'), \sprintf("INSERT INTO %s VALUES (1, 'a')", $table), WritePermissions::all(), true, false, 5_000));

        self::assertSame([], $this->ids($table));
    }

    public function testTheHeartbeatMovesWhileTheScriptRuns(): void
    {
        $table = $this->table('TARGET_POSTGRES_URL');
        $run = $this->queued(2);
        $clock = $this->clock;
        $executor = new ScriptRunExecutor($this->runs, self::driverRegistry(), new ScriptService(new ScriptAnalyzer()), $this->clock, afterStatement: static function () use ($clock): void {
            $clock->sleep(10);
        });

        $executor->run($this->request($run, 'TARGET_POSTGRES_URL', \sprintf("INSERT INTO %1\$s VALUES (1, 'a'); INSERT INTO %1\$s VALUES (2, 'b');", $table)));

        $done = $this->reload($run);
        self::assertEquals(new \DateTimeImmutable('2026-11-19 01:00:20 UTC'), $done->getHeartbeatAt()->setTimezone(new \DateTimeZone('UTC')));
    }

    private static function driverRegistry(): DriverRegistry
    {
        $drivers = self::getContainer()->get(DriverRegistry::class);
        self::assertInstanceOf(DriverRegistry::class, $drivers);

        return $drivers;
    }
}
