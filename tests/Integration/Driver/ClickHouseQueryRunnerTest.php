<?php

declare(strict_types=1);

namespace App\Tests\Integration\Driver;

use App\Core\Guard\StatementKind;
use App\Core\Query\Exception\QueryFailedException;
use App\Core\Query\Exception\QueryTimeoutException;
use App\Core\Query\QueryMode;
use App\Core\Query\QueryOptions;
use App\Driver\ClickHouse\ClickHouseException;
use App\Driver\ClickHouse\ClickHouseHttp;
use App\Driver\ClickHouse\ClickHouseQueryRunner;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\HttpClient;

#[Group('integration')]
final class ClickHouseQueryRunnerTest extends TestCase
{
    private const TARGET = 'TARGET_CLICKHOUSE_URL';
    private const SCRATCH = 'terril_scratch';

    private ClickHouseHttp $http;
    private ClickHouseQueryRunner $runner;

    protected function setUp(): void
    {
        $this->http = new ClickHouseHttp(HttpClient::create(), TargetDatabase::parameters(self::TARGET));
        try {
            $this->http->query('SELECT 1');
        } catch (ClickHouseException) {
            self::markTestSkipped('The ClickHouse target is unreachable (docker compose --profile targets up -d --wait).');
        }
        $this->runner = new ClickHouseQueryRunner($this->http);
    }

    protected function tearDown(): void
    {
        try {
            $this->http->query('DROP TABLE IF EXISTS '.self::SCRATCH);
        } catch (ClickHouseException) {
            // Cible absente : rien à nettoyer.
        }
    }

    public function testReadsRowsWithTheirColumns(): void
    {
        $result = $this->runner->run('SELECT id, name FROM categories WHERE id <= 2 ORDER BY id', StatementKind::Read, new QueryOptions());

        self::assertSame(['id', 'name'], $result->columns);
        self::assertSame([[1, 'Maison'], [2, 'Jardin']], $result->rows);
        self::assertFalse($result->truncated);
    }

    public function testCutsAtTheRowLimitAndFlagsIt(): void
    {
        $result = $this->runner->run('SELECT id FROM order_items ORDER BY id', StatementKind::Read, new QueryOptions(maxRows: 10));

        self::assertCount(10, $result->rows);
        self::assertTrue($result->truncated);
    }

    public function testTheLimitIsNotATruncationWhenTheResultFitsExactly(): void
    {
        $result = $this->runner->run('SELECT id FROM categories', StatementKind::Read, new QueryOptions(maxRows: 20));

        self::assertCount(20, $result->rows);
        self::assertFalse($result->truncated);
    }

    public function testCompositeAndHugeValuesAreReadable(): void
    {
        $result = $this->runner->run("SELECT [1, 2] AS a, toUInt64('18446744073709551615') AS b, NULL AS c, 1.5 AS d", StatementKind::Read, new QueryOptions());

        self::assertSame([['[1,2]', '18446744073709551615', null, 1.5]], $result->rows);
    }

    public function testTheEngineItselfRefusesWritesInReadOnlyMode(): void
    {
        // On contourne volontairement le garde : c'est le moteur qui doit refuser.
        foreach (['INSERT INTO categories VALUES (99, NULL, \'x\')', 'DROP TABLE categories', 'CREATE TABLE '.self::SCRATCH.' (a UInt8) ENGINE = Memory', 'ALTER TABLE categories DELETE WHERE 1'] as $sql) {
            try {
                $this->runner->run($sql, StatementKind::Write, new QueryOptions(QueryMode::ReadOnly));
                self::fail('The engine accepted: '.$sql);
            } catch (QueryFailedException $e) {
                self::assertStringContainsStringIgnoringCase('readonly', $e->getMessage());
            }
        }
        self::assertSame(20, $this->runner->run('SELECT count() FROM categories', StatementKind::Read, new QueryOptions())->rows[0][0]);
    }

    public function testTheEngineRefusesToChangeItsOwnSettings(): void
    {
        $this->expectException(QueryFailedException::class);

        $this->runner->run('SELECT 1 SETTINGS readonly = 0', StatementKind::Read, new QueryOptions(QueryMode::ReadOnly));
    }

    public function testTableFunctionsThatReachOutAreStoppedByTheEngineToo(): void
    {
        $this->expectException(QueryFailedException::class);

        $this->runner->run("SELECT * FROM url('http://127.0.0.1:1/x', 'LineAsString')", StatementKind::Read, new QueryOptions(QueryMode::ReadOnly));
    }

    public function testStopsALongQueryAtTheTimeout(): void
    {
        $this->expectException(QueryTimeoutException::class);

        $this->runner->run('SELECT count() FROM numbers(100000000000)', StatementKind::Read, new QueryOptions(timeoutMs: 500));
    }

    public function testReadWriteModeCreatesInsertsAndReportsWrittenRows(): void
    {
        $options = new QueryOptions(QueryMode::ReadWrite);

        $created = $this->runner->run('CREATE TABLE '.self::SCRATCH.' (a UInt8) ENGINE = MergeTree ORDER BY a', StatementKind::Schema, $options);
        $inserted = $this->runner->run('INSERT INTO '.self::SCRATCH.' VALUES (1), (2), (3)', StatementKind::Write, $options);
        $read = $this->runner->run('SELECT sum(a) FROM '.self::SCRATCH, StatementKind::Read, $options);

        self::assertFalse($created->returnsRows());
        self::assertSame(3, $inserted->affectedRows);
        self::assertSame([[6]], $read->rows);
    }

    public function testASyntaxErrorCarriesTheEnginesMessage(): void
    {
        try {
            $this->runner->run('SELEC 1', StatementKind::Read, new QueryOptions());
            self::fail('Expected a failure.');
        } catch (QueryFailedException $e) {
            self::assertStringContainsStringIgnoringCase('syntax', $e->getMessage());
            self::assertStringNotContainsString('version', $e->getMessage());
        }
    }

    public function testMeasuringIsNotSupported(): void
    {
        $this->expectException(\App\Core\Driver\Exception\UnsupportedOperationException::class);
        $this->runner->run('SELECT 1', StatementKind::Read, new QueryOptions(QueryMode::ReadOnly, 30_000, 10, measure: true));
    }
}
