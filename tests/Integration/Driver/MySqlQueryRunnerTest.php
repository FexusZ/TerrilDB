<?php

declare(strict_types=1);

namespace App\Tests\Integration\Driver;

use App\Core\Driver\Exception\TargetConnectionFailedException;
use App\Core\Guard\StatementKind;
use App\Core\Query\Exception\QueryFailedException;
use App\Core\Query\Exception\QueryTimeoutException;
use App\Core\Query\QueryMode;
use App\Core\Query\QueryOptions;
use App\Core\Query\QueryResult;
use App\Driver\DbalConnector;
use App\Driver\MySql\MySqlQueryRunner;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('integration')]
final class MySqlQueryRunnerTest extends TestCase
{
    private Connection $connection;
    private MySqlQueryRunner $runner;

    /**
     * @return iterable<string, array{string}>
     */
    public static function targets(): iterable
    {
        yield 'MySQL' => ['TARGET_MYSQL_URL'];
        yield 'MySQL 26' => ['TARGET_MYSQL26_URL'];
        yield 'MariaDB' => ['TARGET_MARIADB_URL'];
        yield 'MariaDB 13' => ['TARGET_MARIADB13_URL'];
    }

    private function open(string $envVar): void
    {
        $engine = TargetDatabase::engine($envVar);
        try {
            $this->connection = (new DbalConnector())->connect($engine, TargetDatabase::parameters($envVar));
        } catch (TargetConnectionFailedException) {
            self::markTestSkipped(\sprintf('%s is unreachable (docker compose --profile targets up -d --wait).', $envVar));
        }
        $this->runner = new MySqlQueryRunner($this->connection, $engine);
    }

    protected function tearDown(): void
    {
        if (isset($this->connection)) {
            $this->connection->close();
        }
    }

    private function read(string $sql, int $maxRows = 1000): QueryResult
    {
        return $this->runner->run($sql, StatementKind::Read, new QueryOptions(QueryMode::ReadOnly, 30_000, $maxRows));
    }

    private function write(string $sql, StatementKind $kind = StatementKind::Write): QueryResult
    {
        return $this->runner->run($sql, $kind, new QueryOptions(QueryMode::ReadWrite, 30_000, 1000));
    }

    #[DataProvider('targets')]
    public function testASelectReturnsColumnsAndRows(string $envVar): void
    {
        $this->open($envVar);

        $result = $this->read('select id, name from categories order by id limit 3');

        self::assertSame(['id', 'name'], $result->columns);
        self::assertSame(3, $result->rowCount());
        self::assertFalse($result->truncated);
        self::assertNull($result->affectedRows);
    }

    #[DataProvider('targets')]
    public function testTheRowLimitTruncatesAndTheConnectionStaysUsable(string $envVar): void
    {
        $this->open($envVar);

        $result = $this->read('select * from order_items', 100);

        self::assertSame(100, $result->rowCount());
        self::assertTrue($result->truncated);
        self::assertSame([[1]], $this->read('select 1')->rows, 'le reste du résultat a été écarté proprement');
    }

    #[DataProvider('targets')]
    public function testExactlyTheLimitIsNotTruncated(string $envVar): void
    {
        $this->open($envVar);
        $five = 'with recursive n(x) as (select 1 union all select x + 1 from n where x < 5) select x from n';

        self::assertFalse($this->read($five, 5)->truncated);
        self::assertTrue($this->read($five, 4)->truncated);
    }

    #[DataProvider('targets')]
    public function testAnEmptyResultStillHasItsColumns(string $envVar): void
    {
        $this->open($envVar);

        $result = $this->read('select id, name from categories where id < 0');

        self::assertSame(['id', 'name'], $result->columns);
        self::assertSame(0, $result->rowCount());
        self::assertTrue($result->returnsRows());
    }

    #[DataProvider('targets')]
    public function testTrailingSemicolonsAndCommentsAreAccepted(string $envVar): void
    {
        $this->open($envVar);

        self::assertSame(1, $this->read("select 1 as one; -- done\n")->rowCount());
    }

    #[DataProvider('targets')]
    public function testValuesAreNormalizedForTransport(string $envVar): void
    {
        $this->open($envVar);

        $result = $this->read("select 'é' as t, 1 as i, 1.5 as n, null as z, x'ff00' as raw");

        self::assertSame([['é', 1, '1.5', null, '0xff00']], $result->rows);
    }

    #[DataProvider('targets')]
    public function testInspectionStatementsReturnRows(string $envVar): void
    {
        $this->open($envVar);
        $options = new QueryOptions();

        self::assertGreaterThanOrEqual(7, $this->runner->run('show tables', StatementKind::Inspect, $options)->rowCount());
        self::assertSame(3, $this->runner->run('describe categories', StatementKind::Inspect, $options)->rowCount());
        self::assertGreaterThan(0, $this->runner->run('explain select * from categories', StatementKind::Inspect, $options)->rowCount());
    }

    #[DataProvider('targets')]
    public function testTheEngineItselfRefusesWritesInReadOnlyMode(string $envVar): void
    {
        $this->open($envVar);

        try {
            $this->runner->run("insert into categories (name) values ('sneaky')", StatementKind::Write, new QueryOptions(QueryMode::ReadOnly));
            self::fail('Expected the engine to refuse the write.');
        } catch (QueryFailedException $e) {
            self::assertStringContainsStringIgnoringCase('read only', $e->getMessage());
        }
        self::assertSame([[0]], $this->read("select count(*) from categories where name = 'sneaky'")->rows);
    }

    #[DataProvider('targets')]
    public function testSchemaChangesCannotEscapeTheReadOnlyMode(string $envVar): void
    {
        $this->open($envVar);

        // Un simple START TRANSACTION READ ONLY laisserait passer le DDL (validation implicite) : le mode de
        // session, lui, le refuse.
        try {
            $this->runner->run('create table sneaky (a int)', StatementKind::Schema, new QueryOptions(QueryMode::ReadOnly));
            self::fail('Expected the engine to refuse the schema change.');
        } catch (QueryFailedException) {
        }
        self::assertSame([[0]], $this->read("select count(*) from information_schema.tables where table_schema = database() and table_name = 'sneaky'")->rows);
    }

    #[DataProvider('targets')]
    public function testReadOnlyModeDoesNotStickToLaterReadWriteRuns(string $envVar): void
    {
        $this->open($envVar);
        $this->read('select 1');

        $this->write('create temporary table scratch (a int)', StatementKind::Schema);
        $inserted = $this->write('insert into scratch values (1)');

        self::assertSame(1, $inserted->affectedRows);
    }

    #[DataProvider('targets')]
    public function testASlowQueryIsStopped(string $envVar): void
    {
        $this->open($envVar);
        $started = microtime(true);

        try {
            $this->runner->run(
                'select sum(a.id + b.id + c.id) from order_items a cross join order_items b cross join order_items c',
                StatementKind::Read,
                new QueryOptions(QueryMode::ReadOnly, 300, 10),
            );
            self::fail('Expected a timeout.');
        } catch (QueryTimeoutException) {
            self::assertLessThan(5.0, microtime(true) - $started);
        }
        self::assertSame([[1]], $this->read('select 1')->rows, 'la connexion reste utilisable');
    }

    #[DataProvider('targets')]
    public function testAnEngineErrorIsReportedAndTheConnectionStaysUsable(string $envVar): void
    {
        $this->open($envVar);

        try {
            $this->read('selec 1');
            self::fail('Expected an error.');
        } catch (QueryFailedException $e) {
            self::assertStringContainsStringIgnoringCase('syntax', $e->getMessage());
        }
        self::assertSame([[1]], $this->read('select 1')->rows);
    }

    #[DataProvider('targets')]
    public function testWritesAreCommittedInReadWriteMode(string $envVar): void
    {
        $this->open($envVar);
        $this->write('create temporary table scratch (a int)', StatementKind::Schema);

        $inserted = $this->write('insert into scratch values (1), (2), (3)');
        $updated = $this->write('update scratch set a = a + 10 where a > 1');

        self::assertSame(3, $inserted->affectedRows);
        self::assertSame(2, $updated->affectedRows);
        self::assertFalse($inserted->returnsRows());
        self::assertSame([[1], [12], [13]], $this->write('select a from scratch order by a', StatementKind::Read)->rows);
    }

    #[DataProvider('targets')]
    public function testAFailingWriteIsRolledBackWhole(string $envVar): void
    {
        $this->open($envVar);
        $this->write('create temporary table scratch (a int primary key)', StatementKind::Schema);

        try {
            $this->write('insert into scratch values (1), (2), (2)');
            self::fail('Expected a duplicate key.');
        } catch (QueryFailedException) {
        }

        self::assertSame([[0]], $this->write('select count(*) from scratch', StatementKind::Read)->rows);
    }

    #[DataProvider('targets')]
    public function testMultipleStatementsAreNotExecutedEvenIfTheyReachTheRunner(string $envVar): void
    {
        $this->open($envVar);

        try {
            $this->read("select 1; insert into categories (name) values ('sneaky')");
            self::fail('Expected a refusal.');
        } catch (QueryFailedException) {
        }
        self::assertSame([[0]], $this->read("select count(*) from categories where name = 'sneaky'")->rows);
    }

    #[DataProvider('targets')]
    public function testMeasuringReadsEveryRowButKeepsOnlyAPreview(string $envVar): void
    {
        $this->open($envVar);

        $result = $this->runner->run('select * from order_items', StatementKind::Read, new QueryOptions(QueryMode::ReadOnly, 30_000, 100, measure: true));
        self::assertSame(100, $result->rowCount());
        self::assertGreaterThan(5000, $result->totalRows);
        self::assertTrue($result->truncated);

        $limited = $this->runner->run('select * from order_items limit 250', StatementKind::Read, new QueryOptions(QueryMode::ReadOnly, 30_000, 100, measure: true));
        self::assertSame(250, $limited->totalRows);
        self::assertNull($this->read('select * from order_items', 10)->totalRows);
    }

    #[DataProvider('targets')]
    public function testMeasuringStillHonoursTheTimeout(string $envVar): void
    {
        $this->open($envVar);

        $this->expectException(QueryTimeoutException::class);
        $this->runner->run('select sum(a.id + b.id + c.id) from order_items a cross join order_items b cross join order_items c', StatementKind::Read, new QueryOptions(QueryMode::ReadOnly, 300, 100, measure: true));
    }
}
