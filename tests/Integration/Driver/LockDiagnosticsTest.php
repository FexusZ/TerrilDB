<?php

declare(strict_types=1);

namespace App\Tests\Integration\Driver;

use App\Core\Connection\ConnectionParameters;
use App\Core\Connection\Engine;
use App\Core\Diagnostics\Availability;
use App\Core\Diagnostics\LockKind;
use App\Core\Diagnostics\LockReport;
use App\Core\Driver\Driver;
use App\Core\Driver\Exception\TargetConnectionFailedException;
use App\Core\Driver\TargetConnection;
use App\Driver\ClickHouse\ClickHouseDriver;
use App\Driver\DbalConnector;
use App\Driver\MySql\MySqlDriver;
use App\Driver\PostgreSql\PostgreSqlDriver;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\HttpClient;

/**
 * Transactions longues et verrous, lus sur les vrais moteurs en provoquant un vrai blocage (une transaction tient une ligne,
 * une seconde attend dans un sous-processus), puis avec un compte aux droits réduits.
 */
#[Group('integration')]
final class LockDiagnosticsTest extends TestCase
{
    private ?LockScenario $scenario = null;

    protected function tearDown(): void
    {
        $this->scenario?->cleanup();
        $this->scenario = null;
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function targets(): iterable
    {
        yield from TargetDatabase::relational();
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function mysqlTargets(): iterable
    {
        yield 'MySQL' => ['TARGET_MYSQL_URL'];
        yield 'MySQL 26' => ['TARGET_MYSQL26_URL'];
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function targetsWithPrivilege(): iterable
    {
        yield 'MySQL' => ['TARGET_MYSQL_URL', 'PROCESS'];
        yield 'MySQL 26' => ['TARGET_MYSQL26_URL', 'PROCESS'];
        yield 'MariaDB' => ['TARGET_MARIADB_URL', 'PROCESS'];
        yield 'MariaDB 13' => ['TARGET_MARIADB13_URL', 'PROCESS'];
        yield 'PostgreSQL' => ['TARGET_POSTGRES_URL', 'pg_read_all_stats'];
    }

    private function driver(Engine $engine): Driver
    {
        return match ($engine) {
            Engine::MySql, Engine::MariaDb => new MySqlDriver(new DbalConnector()),
            Engine::PostgreSql => new PostgreSqlDriver(new DbalConnector()),
            Engine::ClickHouse => new ClickHouseDriver(HttpClient::create()),
            Engine::Redis => throw new \LogicException('Redis has no driver here.'),
        };
    }

    private function open(string $envVar, ?ConnectionParameters $parameters = null): TargetConnection
    {
        $engine = TargetDatabase::engine($envVar);
        try {
            return $this->driver($engine)->connect($engine, $parameters ?? TargetDatabase::parameters($envVar));
        } catch (TargetConnectionFailedException) {
            self::markTestSkipped(\sprintf('%s is unreachable (docker compose --profile targets up -d --wait).', $envVar));
        }
    }

    private function scenario(string $envVar, string $holds = 'update'): LockScenario
    {
        $this->open($envVar)->close();

        return $this->scenario = LockScenario::begin($envVar, $holds);
    }

    #[DataProvider('targets')]
    public function testAnOpenTransactionIsSeenWithoutWaitingForAnything(string $envVar): void
    {
        $scenario = $this->scenario($envVar);
        $target = $this->open($envVar);

        $report = $target->diagnostics()->locks()->locks();
        $target->close();

        self::assertSame(Availability::Available, $report->status->availability);
        $mine = array_values(array_filter($report->transactions, static fn ($t): bool => $t->session === $scenario->holderId()));
        self::assertCount(1, $mine);
        self::assertTrue($mine[0]->sleeping, 'The holder runs nothing: it is asleep inside its transaction.');
        self::assertGreaterThanOrEqual(0, $mine[0]->seconds);
        self::assertSame(TargetDatabase::parameters($envVar)->username, $mine[0]->user);
        if (Engine::PostgreSql !== TargetDatabase::engine($envVar)) {
            self::assertGreaterThanOrEqual(1, $mine[0]->rowsLocked);
            self::assertSame(1, $mine[0]->rowsModified);
        }
        self::assertSame([], array_filter($report->waits, static fn ($w): bool => $w->blocking->id === $scenario->holderId()), 'Nobody waits yet.');
    }

    #[DataProvider('targets')]
    public function testTheSessionThatReadsIsNotListedAsAnOpenTransaction(string $envVar): void
    {
        $target = $this->open($envVar);

        $own = array_values(array_filter($target->diagnostics()->sessions()->sessions()->sessions, static fn ($s): bool => $s->current));
        $report = $target->diagnostics()->locks()->locks();
        $target->close();

        self::assertCount(1, $own);
        self::assertSame([], array_filter($report->transactions, static fn ($t): bool => $t->session === $own[0]->id));
    }

    #[DataProvider('targets')]
    public function testWhoBlocksWhomShowsTheHolderAsTheRoot(string $envVar): void
    {
        $scenario = $this->scenario($envVar);
        $scenario->startWaiter();
        $target = $this->open($envVar);

        $report = LockScenario::until(
            static fn (): LockReport => $target->diagnostics()->locks()->locks(),
            static fn (LockReport $r): bool => [] !== $r->waits,
        );
        $target->close();

        self::assertSame(Availability::Available, $report->status->availability);
        $tree = array_values(array_filter($report->tree(), static fn ($n): bool => $n->party->id === $scenario->holderId()));
        self::assertCount(1, $tree, 'The holder is a root: it blocks without waiting.');
        self::assertCount(1, $tree[0]->blocked);
        $waiting = $tree[0]->blocked[0];
        self::assertSame($scenario->waiterId(), $waiting->party->id);
        self::assertSame(LockKind::Row, $waiting->kind);
        self::assertNotNull($waiting->seconds);
        self::assertGreaterThanOrEqual(0, $waiting->seconds);
        self::assertNotNull($waiting->mode);
        self::assertStringContainsString($scenario->table, (string) $waiting->object);
        self::assertStringContainsString('UPDATE', strtoupper((string) $waiting->party->query));
        self::assertLessThanOrEqual(200, mb_strlen((string) $waiting->party->query));
        $transactions = array_map(static fn ($t): string => $t->session, $report->transactions);
        self::assertContains($scenario->holderId(), $transactions);
        self::assertContains($scenario->waiterId(), $transactions);
        $scenario->release();
        $after = $this->open($envVar);
        $gone = $after->diagnostics()->locks()->locks();
        $after->close();
        self::assertSame([], array_filter($gone->waits, static fn ($w): bool => $w->blocking->id === $scenario->holderId()), 'Once released, nobody waits.');
    }

    #[DataProvider('mysqlTargets')]
    public function testAMetadataLockQueueBehindADdlIsShown(string $envVar): void
    {
        $scenario = $this->scenario($envVar, 'read');
        $scenario->startWaiter('ALTER TABLE %s ADD COLUMN extra INT NULL');
        $target = $this->open($envVar);

        $report = LockScenario::until(
            static fn (): LockReport => $target->diagnostics()->locks()->locks(),
            static fn (LockReport $r): bool => [] !== $r->waits,
        );
        $target->close();

        $metadata = array_values(array_filter($report->waits, static fn ($w): bool => LockKind::Metadata === $w->kind && $w->blocking->id === $scenario->holderId()));
        self::assertCount(1, $metadata);
        self::assertSame($scenario->waiterId(), $metadata[0]->waiting->id);
        self::assertStringContainsString($scenario->table, (string) $metadata[0]->object);
        self::assertStringContainsString('ALTER', strtoupper((string) $metadata[0]->waiting->query));
    }

    #[DataProvider('targetsWithPrivilege')]
    public function testAnAccountWithoutTheRightGetsAStateNotAnError(string $envVar, string $privilege): void
    {
        $parameters = TargetDatabase::parameters($envVar);
        $user = 'tdb_lock_'.bin2hex(random_bytes(4));
        $password = bin2hex(random_bytes(12));
        $admin = (new DbalConnector())->connect(TargetDatabase::engine($envVar), $parameters);
        $postgres = Engine::PostgreSql === TargetDatabase::engine($envVar);
        $admin->executeStatement($postgres ? \sprintf("CREATE ROLE %s LOGIN PASSWORD '%s'", $user, $password) : \sprintf("CREATE USER '%s'@'%%' IDENTIFIED BY '%s'", $user, $password));
        if (!$postgres) {
            $admin->executeStatement(\sprintf("GRANT SELECT ON shop.* TO '%s'@'%%'", $user));
        }
        $as = new ConnectionParameters($parameters->host, $parameters->port, $parameters->database, $user, $password);
        try {
            $reduced = $this->open($envVar, $as);
            $status = $reduced->diagnostics()->locks()->status();
            $report = $reduced->diagnostics()->locks()->locks();
            $reduced->close();

            self::assertSame(Availability::PermissionDenied, $status->availability);
            self::assertSame($privilege, $status->privilege);
            self::assertSame(Availability::PermissionDenied, $report->status->availability);
            self::assertSame([], $report->transactions);
            self::assertSame([], $report->waits);

            $admin->executeStatement($postgres ? \sprintf('GRANT pg_monitor TO %s', $user) : \sprintf("GRANT PROCESS ON *.* TO '%s'@'%%'", $user));
            if (Engine::MySql === TargetDatabase::engine($envVar)) {
                $admin->executeStatement(\sprintf("GRANT SELECT ON performance_schema.* TO '%s'@'%%'", $user));
                $admin->executeStatement(\sprintf("GRANT SELECT ON sys.* TO '%s'@'%%'", $user));
            }
            $granted = $this->open($envVar, $as);
            self::assertSame(Availability::Available, $granted->diagnostics()->locks()->status()->availability);
            self::assertSame(Availability::Available, $granted->diagnostics()->locks()->locks()->status->availability);
            $granted->close();
        } finally {
            $admin->executeStatement($postgres ? \sprintf('DROP ROLE IF EXISTS %s', $user) : \sprintf("DROP USER IF EXISTS '%s'@'%%'", $user));
            $admin->close();
        }
    }

    #[DataProvider('mysqlTargets')]
    public function testMySqlWithProcessButNotPerformanceSchemaSaysWhatIsMissing(string $envVar): void
    {
        $parameters = TargetDatabase::parameters($envVar);
        $user = 'tdb_lock_'.bin2hex(random_bytes(4));
        $password = bin2hex(random_bytes(12));
        $admin = (new DbalConnector())->connect(Engine::MySql, $parameters);
        $admin->executeStatement(\sprintf("CREATE USER '%s'@'%%' IDENTIFIED BY '%s'", $user, $password));
        $admin->executeStatement(\sprintf("GRANT SELECT ON shop.* TO '%s'@'%%'", $user));
        $admin->executeStatement(\sprintf("GRANT PROCESS ON *.* TO '%s'@'%%'", $user));
        try {
            $reduced = $this->open($envVar, new ConnectionParameters($parameters->host, $parameters->port, $parameters->database, $user, $password));
            $status = $reduced->diagnostics()->locks()->status();
            $report = $reduced->diagnostics()->locks()->locks();
            $reduced->close();

            self::assertSame(Availability::PermissionDenied, $status->availability);
            self::assertSame('SELECT ON performance_schema', $status->privilege);
            self::assertSame(Availability::PermissionDenied, $report->status->availability);
        } finally {
            $admin->executeStatement(\sprintf("DROP USER IF EXISTS '%s'@'%%'", $user));
            $admin->close();
        }
    }

    public function testClickHouseHasNoLocks(): void
    {
        $target = $this->open('TARGET_CLICKHOUSE_URL');

        $status = $target->diagnostics()->capabilities()['locks'];
        $target->close();

        self::assertSame(Availability::Unsupported, $status->availability);
    }
}
