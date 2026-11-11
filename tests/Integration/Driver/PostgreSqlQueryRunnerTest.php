<?php

declare(strict_types=1);

namespace App\Tests\Integration\Driver;

use App\Core\Driver\Exception\TargetConnectionFailedException;
use App\Core\Guard\StatementKind;
use App\Core\Query\Exception\QueryFailedException;
use App\Core\Query\Exception\QueryTimeoutException;
use App\Core\Query\QueryMode;
use App\Core\Query\QueryOptions;
use App\Driver\DbalConnector;
use App\Driver\PostgreSql\PostgreSqlQueryRunner;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('integration')]
final class PostgreSqlQueryRunnerTest extends TestCase
{
    private const TARGET = 'TARGET_POSTGRES_URL';

    private Connection $connection;
    private PostgreSqlQueryRunner $runner;

    protected function setUp(): void
    {
        try {
            $this->connection = (new DbalConnector())->connect(TargetDatabase::engine(self::TARGET), TargetDatabase::parameters(self::TARGET));
        } catch (TargetConnectionFailedException) {
            self::markTestSkipped('The PostgreSQL target is unreachable (docker compose --profile targets up -d --wait).');
        }
        $this->runner = new PostgreSqlQueryRunner($this->connection);
    }

    protected function tearDown(): void
    {
        if (isset($this->connection)) {
            $this->connection->close();
        }
    }

    private function read(string $sql, int $maxRows = 1000): \App\Core\Query\QueryResult
    {
        return $this->runner->run($sql, StatementKind::Read, new QueryOptions(QueryMode::ReadOnly, 30_000, $maxRows));
    }

    private function write(string $sql, StatementKind $kind = StatementKind::Write): \App\Core\Query\QueryResult
    {
        return $this->runner->run($sql, $kind, new QueryOptions(QueryMode::ReadWrite, 30_000, 1000));
    }

    public function testASelectReturnsColumnsAndRows(): void
    {
        $result = $this->read('select id, name from categories order by id limit 3');

        self::assertSame(['id', 'name'], $result->columns);
        self::assertSame(3, $result->rowCount());
        self::assertFalse($result->truncated);
        self::assertNull($result->affectedRows);
        self::assertGreaterThanOrEqual(0, $result->durationMs);
    }

    public function testTheRowLimitTruncatesAndSaysSo(): void
    {
        $result = $this->read('select * from order_items', 100);

        self::assertSame(100, $result->rowCount());
        self::assertTrue($result->truncated);
        self::assertCount(6, $result->columns);
    }

    public function testExactlyTheLimitIsNotTruncated(): void
    {
        self::assertFalse($this->read('select generate_series(1, 5)', 5)->truncated);
        self::assertTrue($this->read('select generate_series(1, 5)', 4)->truncated);
    }

    public function testAnEmptyResultStillHasItsColumns(): void
    {
        $result = $this->read('select id, name from categories where id < 0');

        self::assertSame(['id', 'name'], $result->columns);
        self::assertSame(0, $result->rowCount());
        self::assertTrue($result->returnsRows());
    }

    public function testTrailingSemicolonsAndCommentsAreAccepted(): void
    {
        self::assertSame(1, $this->read("select 1 as one; -- done\n")->rowCount());
    }

    public function testQuestionMarkOperatorsAreNotTreatedAsPlaceholders(): void
    {
        $result = $this->read('select \'{"a": 1}\'::jsonb ? \'a\' as has_a');

        self::assertSame([[true]], $result->rows);
    }

    public function testWithAndValuesAreReadable(): void
    {
        self::assertSame([[1]], $this->read('with c as (select 1 as a) select a from c')->rows);
        self::assertSame([[1], [2]], $this->read('values (1), (2)')->rows);
    }

    public function testValuesAreNormalizedForTransport(): void
    {
        $result = $this->read("select 'é' as t, 1 as i, 1.5::numeric as n, true as b, null as z, '\\xff00'::bytea as raw");

        self::assertSame([['é', 1, '1.5', true, null, '0xff00']], $result->rows);
    }

    public function testInspectionStatementsReturnRows(): void
    {
        self::assertSame(1, $this->runner->run('show server_version', StatementKind::Inspect, new QueryOptions())->rowCount());
        self::assertGreaterThan(0, $this->runner->run('explain select * from categories', StatementKind::Inspect, new QueryOptions())->rowCount());
    }

    public function testTheEngineItselfRefusesWritesInReadOnlyMode(): void
    {
        // Même si le garde était contourné, la transaction READ ONLY du moteur refuse.
        try {
            $this->runner->run("insert into categories (name) values ('sneaky')", StatementKind::Write, new QueryOptions(QueryMode::ReadOnly));
            self::fail('Expected the engine to refuse the write.');
        } catch (QueryFailedException $e) {
            self::assertStringContainsStringIgnoringCase('read-only', $e->getMessage());
        }
        self::assertSame([[0]], $this->read("select count(*) from categories where name = 'sneaky'")->rows);
    }

    public function testReadOnlyModeAlsoRefusesSchemaChanges(): void
    {
        $this->expectException(QueryFailedException::class);

        $this->runner->run('create table sneaky (a int)', StatementKind::Schema, new QueryOptions(QueryMode::ReadOnly));
    }

    public function testASlowQueryIsStopped(): void
    {
        $started = microtime(true);

        try {
            $this->runner->run('select pg_sleep(5)', StatementKind::Read, new QueryOptions(QueryMode::ReadOnly, 300, 10));
            self::fail('Expected a timeout.');
        } catch (QueryTimeoutException) {
            self::assertLessThan(3.0, microtime(true) - $started);
        }
        self::assertSame([[1]], $this->read('select 1')->rows, 'la connexion reste utilisable');
    }

    public function testAnEngineErrorIsReportedAndTheConnectionStaysUsable(): void
    {
        try {
            $this->read('selec 1');
            self::fail('Expected an error.');
        } catch (QueryFailedException $e) {
            self::assertStringContainsStringIgnoringCase('syntax', $e->getMessage());
        }
        self::assertSame([[1]], $this->read('select 1')->rows);
    }

    public function testWritesAreCommittedInReadWriteMode(): void
    {
        $this->write('create temp table scratch (a int)', StatementKind::Schema);

        $inserted = $this->write('insert into scratch values (1), (2), (3)');
        $updated = $this->write('update scratch set a = a + 10 where a > 1');

        self::assertSame(3, $inserted->affectedRows);
        self::assertSame(2, $updated->affectedRows);
        self::assertFalse($inserted->returnsRows());
        self::assertSame([[1], [12], [13]], $this->write('select a from scratch order by a', StatementKind::Read)->rows);
    }

    public function testAFailingWriteIsRolledBackWhole(): void
    {
        $this->write('create temp table scratch (a int)', StatementKind::Schema);

        try {
            $this->write('insert into scratch select 1 / (x - 2) from generate_series(1, 3) as x');
            self::fail('Expected a division by zero.');
        } catch (QueryFailedException) {
        }

        self::assertSame([[0]], $this->write('select count(*) from scratch', StatementKind::Read)->rows);
    }

    public function testAWriteWithReturningGivesItsRows(): void
    {
        $this->write('create temp table scratch (a int)', StatementKind::Schema);

        $result = $this->write('insert into scratch values (7) returning a');

        self::assertSame([[7]], $result->rows);
        self::assertTrue($result->returnsRows());
    }

    public function testReadOnlyReadsNeverPersistAnything(): void
    {
        $this->write('create temp table scratch (a int)', StatementKind::Schema);
        $this->write('insert into scratch values (1)');

        // Une lecture ne peut pas laisser de trace : la transaction est annulée.
        $this->read('select * from scratch');
        $this->connection->executeStatement('select 1');

        self::assertSame([[1]], $this->write('select count(*) from scratch', StatementKind::Read)->rows);
    }

    public function testMeasuringReadsEveryRowButKeepsOnlyAPreview(): void
    {
        $result = $this->runner->run('select * from order_items', StatementKind::Read, new QueryOptions(QueryMode::ReadOnly, 30_000, 100, measure: true));

        self::assertSame(100, $result->rowCount());
        self::assertGreaterThan(5000, $result->totalRows);
        self::assertTrue($result->truncated);
    }

    public function testMeasuringKeepsTheSqlLimitAndTheReadOnlyTransaction(): void
    {
        $limited = $this->runner->run('select * from order_items limit 250', StatementKind::Read, new QueryOptions(QueryMode::ReadOnly, 30_000, 100, measure: true));
        self::assertSame(250, $limited->totalRows);

        $this->expectException(QueryFailedException::class);
        $this->runner->run('create table terril_measure (id int)', StatementKind::Read, new QueryOptions(QueryMode::ReadOnly, 30_000, 100, measure: true));
    }

    public function testMeasuringStillHonoursTheTimeout(): void
    {
        $this->expectException(QueryTimeoutException::class);
        $this->runner->run('select pg_sleep(5)', StatementKind::Read, new QueryOptions(QueryMode::ReadOnly, 300, 100, measure: true));
    }

    public function testWithoutMeasuringTheTotalIsUnknown(): void
    {
        self::assertNull($this->read('select * from order_items', 10)->totalRows);
    }
}
