<?php

declare(strict_types=1);

namespace App\Tests\Core\Optimizer;

use App\Core\Connection\Engine;
use App\Core\Guard\GuardReason;
use App\Core\Guard\StatementGuard;
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
