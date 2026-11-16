<?php

declare(strict_types=1);

namespace App\Tests\Core\Optimizer;

use App\Core\Connection\Engine;
use App\Core\Guard\GuardReason;
use App\Core\Guard\StatementGuard;
use App\Core\Optimizer\Exception\PlanNotAvailableException;
use App\Core\Optimizer\Exception\UnreadablePlanException;
use App\Core\Optimizer\PlanAnalyzer;
use App\Core\Optimizer\PlanKind;
use App\Core\Optimizer\PlanNode;
use App\Core\Optimizer\PlanService;
use App\Core\Optimizer\QueryPlan;
use App\Core\Query\Exception\QueryRefusedException;
use App\Core\Query\QueryResult;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PlanServiceTest extends TestCase
{
    private function service(): PlanService
    {
        return new PlanService(new StatementGuard());
    }

    private function fixture(string $name): string
    {
        return (string) file_get_contents(__DIR__.'/../../Fixtures/plans/'.$name.'.json');
    }

    public function testBuildsTheExplainStatementOfEachEngine(): void
    {
        self::assertSame('EXPLAIN (FORMAT JSON) select * from orders', $this->service()->statementFor(Engine::PostgreSql, 'select * from orders'));
        self::assertSame('EXPLAIN FORMAT=JSON select * from orders', $this->service()->statementFor(Engine::MySql, 'select * from orders'));
        self::assertSame('EXPLAIN FORMAT=JSON select * from orders', $this->service()->statementFor(Engine::MariaDb, 'select * from orders'));
        self::assertSame('EXPLAIN json=1, indexes=1 select * from orders', $this->service()->statementFor(Engine::ClickHouse, 'select * from orders'));
    }

    public function testBuildsTheExplainOfAWriteWithoutExecutingIt(): void
    {
        self::assertSame("EXPLAIN (FORMAT JSON) delete from orders where status = 'x'", $this->service()->statementForWrite(Engine::PostgreSql, "delete from orders where status = 'x';"));
        self::assertSame('EXPLAIN FORMAT=JSON update orders set a = 1 where id = 2', $this->service()->statementForWrite(Engine::MySql, 'update orders set a = 1 where id = 2'));
        self::assertSame('EXPLAIN FORMAT=JSON insert into t (a) select a from u', $this->service()->statementForWrite(Engine::MariaDb, 'insert into t (a) select a from u'));
        self::assertSame('EXPLAIN (FORMAT JSON) replace into t (a) values (1)', $this->service()->statementForWrite(Engine::PostgreSql, 'replace into t (a) values (1)'));
    }

    /**
     * @return iterable<string, array{Engine, non-empty-string}>
     */
    public static function neverAnalyzed(): iterable
    {
        foreach ([Engine::PostgreSql, Engine::MySql, Engine::MariaDb, Engine::ClickHouse] as $engine) {
            yield $engine->value.' read' => [$engine, 'select * from analyze_log where analyze = 1'];
        }
        foreach ([Engine::PostgreSql, Engine::MySql, Engine::MariaDb] as $engine) {
            yield $engine->value.' delete' => [$engine, 'delete from analyze_log where analyze = 1'];
            yield $engine->value.' update' => [$engine, 'update analyze_log set analyze = 2'];
            yield $engine->value.' insert' => [$engine, 'insert into analyze_log (analyze) select 1'];
        }
    }

    /**
     * L'EXPLAIN ANALYZE exécute l'instruction : le préfixe construit ne le contient jamais (le texte de l'utilisateur,
     * lui, peut citer le mot dans un nom).
     */
    #[DataProvider('neverAnalyzed')]
    public function testTheBuiltPrefixNeverAsksForAnAnalyze(Engine $engine, string $sql): void
    {
        \assert('' !== $sql);
        $built = str_starts_with(strtolower($sql), 'select')
            ? $this->service()->statementFor($engine, $sql)
            : $this->service()->statementForWrite($engine, $sql);

        self::assertStringEndsWith($sql, $built);
        $prefix = substr($built, 0, -\strlen($sql));
        self::assertStringStartsWith('EXPLAIN', $prefix);
        self::assertStringNotContainsStringIgnoringCase('analy', $prefix);
    }

    /**
     * @return iterable<string, array{string, GuardReason}>
     */
    public static function writeRefused(): iterable
    {
        yield 'a read' => ['select * from orders', GuardReason::NotReadOnly];
        yield 'an analyze of a write' => ['explain analyze delete from orders', GuardReason::NotReadOnly];
        yield 'an explain' => ['explain delete from orders', GuardReason::NotReadOnly];
        yield 'a schema change' => ['drop table orders', GuardReason::NotReadOnly];
        yield 'a truncate' => ['truncate orders', GuardReason::NotReadOnly];
        yield 'a call' => ['call purge()', GuardReason::NotReadOnly];
        yield 'a write behind a with' => ['with d as (select 1) delete from orders', GuardReason::NotReadOnly];
        yield 'two statements' => ['delete from a; delete from b', GuardReason::MultipleStatements];
        yield 'nothing' => ['  ', GuardReason::EmptyStatement];
        yield 'a forbidden construct' => ["select * from t into outfile '/tmp/x'", GuardReason::ForbiddenConstruct];
    }

    #[DataProvider('writeRefused')]
    public function testOnlyAPlainWriteCanBeExplainedAsAWrite(string $sql, GuardReason $reason): void
    {
        try {
            $this->service()->statementForWrite(Engine::PostgreSql, $sql);
            self::fail('Expected a refusal.');
        } catch (QueryRefusedException $e) {
            self::assertSame($reason, $e->reason);
        }
    }

    public function testAWriteWithoutWhereIsStillExplainedBecauseNothingRuns(): void
    {
        self::assertSame('EXPLAIN FORMAT=JSON delete from orders', $this->service()->statementForWrite(Engine::MySql, 'delete from orders'));
    }

    public function testNoWritePlanForClickHouseOrRedis(): void
    {
        foreach ([Engine::ClickHouse, Engine::Redis] as $engine) {
            try {
                $this->service()->statementForWrite($engine, 'delete from orders');
                self::fail('Expected no plan.');
            } catch (PlanNotAvailableException) {
            }
        }
        $this->addToAssertionCount(1);
    }

    public function testTheReadPathStillRefusesAWrite(): void
    {
        $this->expectException(QueryRefusedException::class);

        $this->service()->statementFor(Engine::MySql, 'delete from orders');
    }

    public function testBuildsTheMeasuredStatementOfEachEngine(): void
    {
        self::assertSame('EXPLAIN (ANALYZE, FORMAT JSON, BUFFERS) select * from orders', $this->service()->measuredStatementFor(Engine::PostgreSql, 'select * from orders;'));
        self::assertSame('EXPLAIN ANALYZE select * from orders', $this->service()->measuredStatementFor(Engine::MySql, ' select * from orders '));
        self::assertSame('ANALYZE FORMAT=JSON select * from orders', $this->service()->measuredStatementFor(Engine::MariaDb, 'select * from orders'));
        self::assertSame('EXPLAIN (ANALYZE, FORMAT JSON, BUFFERS) with c as (select 1 as a) select a from c', $this->service()->measuredStatementFor(Engine::PostgreSql, 'with c as (select 1 as a) select a from c'));
    }

    public function testOnlyTheEnginesThatCanMeasureAreOffered(): void
    {
        self::assertTrue($this->service()->canMeasure(Engine::PostgreSql));
        self::assertTrue($this->service()->canMeasure(Engine::MySql));
        self::assertTrue($this->service()->canMeasure(Engine::MariaDb));
        self::assertFalse($this->service()->canMeasure(Engine::ClickHouse));
        self::assertFalse($this->service()->canMeasure(Engine::Redis));

        foreach ([Engine::ClickHouse, Engine::Redis] as $engine) {
            try {
                $this->service()->measuredStatementFor($engine, 'select 1');
                self::fail('Expected no measure.');
            } catch (PlanNotAvailableException) {
            }
        }
    }

    /**
     * @return iterable<string, array{Engine, string, GuardReason}>
     */
    public static function neverMeasured(): iterable
    {
        foreach ([Engine::PostgreSql, Engine::MySql, Engine::MariaDb] as $engine) {
            $name = $engine->value.' ';
            yield $name.'delete' => [$engine, 'delete from orders where id = 1', GuardReason::NotReadOnly];
            yield $name.'delete without where' => [$engine, 'delete from orders', GuardReason::NotReadOnly];
            yield $name.'update' => [$engine, 'update orders set a = 1 where id = 2', GuardReason::NotReadOnly];
            yield $name.'insert' => [$engine, 'insert into t (a) select a from u', GuardReason::NotReadOnly];
            yield $name.'replace' => [$engine, 'replace into t (a) values (1)', GuardReason::NotReadOnly];
            yield $name.'ddl' => [$engine, 'create table z (a int)', GuardReason::NotReadOnly];
            yield $name.'drop' => [$engine, 'drop table orders', GuardReason::NotReadOnly];
            yield $name.'call' => [$engine, 'call purge()', GuardReason::NotReadOnly];
            yield $name.'a write behind a with' => [$engine, 'with d as (select 1) delete from orders', GuardReason::NotReadOnly];
            yield $name.'an explain' => [$engine, 'explain select 1', GuardReason::NotReadOnly];
            yield $name.'an explain analyze of a write' => [$engine, 'explain analyze delete from orders', GuardReason::NotReadOnly];
            yield $name.'an analyze of a write' => [$engine, 'analyze delete from orders', GuardReason::NotReadOnly];
            yield $name.'an analyze of a read' => [$engine, 'analyze select 1', GuardReason::NotReadOnly];
            yield $name.'two statements' => [$engine, 'select 1; delete from orders', GuardReason::MultipleStatements];
            yield $name.'two reads' => [$engine, 'select 1; select 2', GuardReason::MultipleStatements];
            yield $name.'nothing' => [$engine, '  ', GuardReason::EmptyStatement];
            yield $name.'a lock' => [$engine, 'select * from t for update', GuardReason::ForbiddenConstruct];
            yield $name.'a durable lock' => [$engine, "select get_lock('a', 1)", GuardReason::ForbiddenConstruct];
            yield $name.'a file' => [$engine, "select * from t into outfile '/tmp/x'", GuardReason::ForbiddenConstruct];
        }
        yield 'postgresql a sequence' => [Engine::PostgreSql, "select nextval('s')", GuardReason::NotReadOnly];
        yield 'postgresql a sequence behind a with' => [Engine::PostgreSql, "with n as (select nextval('s')) select * from n", GuardReason::NotReadOnly];
        yield 'postgresql a hidden write' => [Engine::PostgreSql, 'with d as (delete from t returning *) select * from d', GuardReason::NotReadOnly];
        yield 'postgresql a select into' => [Engine::PostgreSql, 'select * into z from t', GuardReason::ForbiddenConstruct];
        yield 'postgresql an advisory lock' => [Engine::PostgreSql, 'select pg_advisory_lock(1)', GuardReason::ForbiddenConstruct];
    }

    /**
     * L'EXPLAIN ANALYZE JOUE l'instruction : il n'est construit que pour une lecture simple, que le garde a classée telle ;
     * tout le reste est un refus net, jamais une mesure.
     */
    #[DataProvider('neverMeasured')]
    public function testAnythingButAPlainReadIsRefusedNotMeasured(Engine $engine, string $sql, GuardReason $reason): void
    {
        try {
            $this->service()->measuredStatementFor($engine, $sql);
            self::fail('Expected a refusal.');
        } catch (QueryRefusedException $e) {
            self::assertSame($reason, $e->reason);
        }
    }

    public function testTheMeasuredStatementIsNeverBuiltFromAWriteMethod(): void
    {
        // La mesure ne passe que par measuredStatementFor : les deux autres chemins ne construisent toujours aucun ANALYZE.
        foreach ([Engine::PostgreSql, Engine::MySql, Engine::MariaDb] as $engine) {
            self::assertStringNotContainsStringIgnoringCase('analy', substr($this->service()->statementFor($engine, 'select 1'), 0, 20));
            self::assertStringNotContainsStringIgnoringCase('analy', substr($this->service()->statementForWrite($engine, 'delete from t where a = 1'), 0, 20));
        }
    }

    public function testParsesTheMeasuredResultOfEachEngine(): void
    {
        $pg = $this->service()->parseMeasured(Engine::PostgreSql, QueryResult::rows(['QUERY PLAN'], [[$this->fixture('postgres-measured-join')]], false, 1));
        $my = $this->service()->parseMeasured(Engine::MySql, QueryResult::rows(['EXPLAIN'], [[(string) file_get_contents(__DIR__.'/../../Fixtures/plans/mysql-measured-join.txt')]], false, 1));
        $maria = $this->service()->parseMeasured(Engine::MariaDb, QueryResult::rows(['ANALYZE'], [[$this->fixture('mariadb-measured-join')]], false, 1));

        foreach ([$pg, $my, $maria] as $plan) {
            self::assertTrue($plan->measured);
            self::assertNotNull($plan->executionTimeMs);
        }
    }

    public function testAMeasuredResultThatIsNotMeasuredIsUnreadable(): void
    {
        foreach ([QueryResult::rows(['x'], [], false, 1), QueryResult::rows(['x'], [[null]], false, 1), QueryResult::rows(['x'], [[$this->fixture('postgres-scan')]], false, 1)] as $result) {
            try {
                $this->service()->parseMeasured(Engine::PostgreSql, $result);
                self::fail('Expected an unreadable plan.');
            } catch (UnreadablePlanException) {
            }
        }
        $this->addToAssertionCount(1);
    }

    public function testNoPlanIsOfferedForAnEngineWithoutOne(): void
    {
        $this->expectException(PlanNotAvailableException::class);

        $this->service()->statementFor(Engine::Redis, 'select * from orders');
    }

    public function testDropsTheTrailingSemicolonAndSpaces(): void
    {
        self::assertSame('EXPLAIN (FORMAT JSON) select 1', $this->service()->statementFor(Engine::PostgreSql, "  select 1 ;;\n  "));
    }

    public function testAcceptsWithQueries(): void
    {
        self::assertStringEndsWith('with c as (select 1 as a) select a from c', $this->service()->statementFor(Engine::PostgreSql, 'with c as (select 1 as a) select a from c'));
    }

    /**
     * @return iterable<string, array{string, GuardReason}>
     */
    public static function refused(): iterable
    {
        yield 'a write' => ['delete from orders', GuardReason::NotReadOnly];
        yield 'a hidden write' => ['with d as (delete from t returning *) select * from d', GuardReason::NotReadOnly];
        yield 'two statements' => ['select 1; select 2', GuardReason::MultipleStatements];
        yield 'nothing' => ['  ', GuardReason::EmptyStatement];
        yield 'a forbidden construct' => ['select * from t for update', GuardReason::ForbiddenConstruct];
        yield 'an inspection' => ['show tables', GuardReason::NotReadOnly];
        yield 'an explain' => ['explain select 1', GuardReason::NotReadOnly];
    }

    #[DataProvider('refused')]
    public function testOnlyAPlainReadCanBeExplained(string $sql, GuardReason $reason): void
    {
        try {
            $this->service()->statementFor(Engine::PostgreSql, $sql);
            self::fail('Expected a refusal.');
        } catch (QueryRefusedException $e) {
            self::assertSame($reason, $e->reason);
        }
    }

    public function testParsesTheResultOfEachEngine(): void
    {
        $postgres = $this->service()->parse(Engine::PostgreSql, QueryResult::rows(['QUERY PLAN'], [[$this->fixture('postgres-scan')]], false, 1));
        $mysql = $this->service()->parse(Engine::MySql, QueryResult::rows(['EXPLAIN'], [[$this->fixture('mysql-scan')]], false, 1));
        $mariadb = $this->service()->parse(Engine::MariaDb, QueryResult::rows(['EXPLAIN'], [[$this->fixture('mariadb-scan')]], false, 1));

        self::assertSame(PlanKind::FullScan, $postgres->root->kind);
        self::assertSame(PlanKind::FullScan, $mysql->root->children[0]->kind);
        self::assertSame(PlanKind::FullScan, $mariadb->root->children[0]->kind);

        $clickhouse = $this->service()->parse(Engine::ClickHouse, QueryResult::rows(['explain'], [[$this->fixture('clickhouse-key')]], false, 1));
        self::assertSame(PlanKind::Other, $clickhouse->root->kind);
        self::assertNull($clickhouse->totalCost);
    }

    public function testAnEmptyOrOddResultIsUnreadable(): void
    {
        foreach ([QueryResult::rows(['x'], [], false, 1), QueryResult::rows(['x'], [[null]], false, 1), QueryResult::rows(['x'], [[42]], false, 1), QueryResult::affected(1, 1)] as $result) {
            try {
                $this->service()->parse(Engine::PostgreSql, $result);
                self::fail('Expected an unreadable plan.');
            } catch (UnreadablePlanException) {
            }
        }
        $this->addToAssertionCount(1);
    }

    public function testAnalyzerFlagsBigFullScansOnly(): void
    {
        $analyzer = new PlanAnalyzer();
        $big = new PlanNode(PlanKind::FullScan, 'Seq Scan', 'orders', rows: 50000, condition: '(status = 1)', fullScan: true);
        $small = new PlanNode(PlanKind::FullScan, 'Seq Scan', 'categories', rows: 24, fullScan: true);
        $indexed = new PlanNode(PlanKind::IndexScan, 'Index Scan', 'orders', 'orders_pkey', 50000);
        $root = new PlanNode(PlanKind::Join, 'Hash Join', children: [$big, $small, $indexed]);

        $findings = $analyzer->analyze(new QueryPlan($root, 10.0));

        self::assertCount(1, $findings);
        self::assertSame('full_scan', $findings[0]->rule);
        self::assertSame('orders', $findings[0]->relation);
        self::assertSame(50000, $findings[0]->rows);
        self::assertSame('(status = 1)', $findings[0]->condition);
    }

    public function testAnalyzerWalksTheWholeTree(): void
    {
        $leaf = new PlanNode(PlanKind::FullScan, 'ALL', 'logs', rows: 2000, fullScan: true);
        $plan = new QueryPlan(new PlanNode(PlanKind::Other, 'Query', children: [new PlanNode(PlanKind::Sort, 'Ordering', children: [new PlanNode(PlanKind::Join, 'Nested loop', children: [$leaf])])]), null);

        self::assertCount(1, (new PlanAnalyzer())->analyze($plan));
    }

    public function testAnalyzerStaysQuietWhenTheEngineGivesNoEstimate(): void
    {
        $plan = new QueryPlan(new PlanNode(PlanKind::FullScan, 'Seq Scan', 'orders', rows: null, fullScan: true), null);

        self::assertSame([], (new PlanAnalyzer())->analyze($plan));
    }
}
