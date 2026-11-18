<?php

declare(strict_types=1);

namespace App\Tests\Core\Optimizer;

use App\Core\Connection\Engine;
use App\Core\Optimizer\MySqlPlanParser;
use App\Core\Optimizer\PlanKind;
use App\Core\Optimizer\PlanNode;
use App\Core\Optimizer\PostgreSqlPlanParser;
use App\Core\Optimizer\QueryPlan;
use App\Core\Optimizer\WritePlanAdvisor;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class WritePlanAdvisorTest extends TestCase
{
    private function real(string $fixture, Engine $engine): QueryPlan
    {
        $json = (string) file_get_contents(__DIR__.'/../../Fixtures/plans/'.$fixture.'.json');

        return Engine::PostgreSql === $engine ? (new PostgreSqlPlanParser())->parse($json) : (new MySqlPlanParser())->parse($json);
    }

    /**
     * @return iterable<string, array{string, Engine, string, int, bool, ?string, bool}>
     */
    public static function realDeletes(): iterable
    {
        yield 'MySQL' => ['mysql-write-delete-scan', Engine::MySql, 'order_items', 7000, true, null, true];
        yield 'MariaDB' => ['mariadb-write-delete-scan', Engine::MariaDb, 'order_items', 7000, true, null, true];
        // PostgreSQL ne verrouille que les lignes touchées : un parcours complet n'y verrouille pas large.
        yield 'PostgreSQL' => ['postgres-write-delete-scan', Engine::PostgreSql, 'order_items', 1400, true, null, false];
    }

    #[DataProvider('realDeletes')]
    public function testADeleteByFullScan(string $fixture, Engine $engine, string $relation, int $rows, bool $fullScan, ?string $index, bool $locksWide): void
    {
        $summary = (new WritePlanAdvisor())->advise($this->real($fixture, $engine), $engine);

        self::assertNotNull($summary);
        self::assertSame('delete', $summary->operation);
        self::assertSame($relation, $summary->relation);
        self::assertSame($rows, $summary->rows);
        self::assertSame($fullScan, $summary->fullScan);
        self::assertSame($index, $summary->index);
        self::assertSame($locksWide, $summary->locksWide);
        self::assertFalse($summary->batchAdvised);
    }

    /**
     * @return iterable<string, array{string, Engine, string}>
     */
    public static function realUpdatesByKey(): iterable
    {
        yield 'MySQL' => ['mysql-write-update-key', Engine::MySql, 'PRIMARY'];
        yield 'MariaDB' => ['mariadb-write-update-key', Engine::MariaDb, 'PRIMARY'];
        yield 'PostgreSQL' => ['postgres-write-update-key', Engine::PostgreSql, 'orders_pkey'];
    }

    #[DataProvider('realUpdatesByKey')]
    public function testAnUpdateByKeyUsesItsIndexAndLocksNarrowly(string $fixture, Engine $engine, string $index): void
    {
        $summary = (new WritePlanAdvisor())->advise($this->real($fixture, $engine), $engine);

        self::assertNotNull($summary);
        self::assertSame('update', $summary->operation);
        self::assertSame('orders', $summary->relation);
        self::assertSame(1, $summary->rows);
        self::assertFalse($summary->fullScan);
        self::assertSame($index, $summary->index);
        self::assertFalse($summary->locksWide);
        self::assertFalse($summary->batchAdvised);
    }

    /**
     * @return iterable<string, array{string, Engine}>
     */
    public static function realInserts(): iterable
    {
        yield 'MySQL' => ['mysql-write-insert-select', Engine::MySql];
        yield 'MySQL 26' => ['mysql26-write-insert-select', Engine::MySql];
        yield 'PostgreSQL' => ['postgres-write-insert-select', Engine::PostgreSql];
    }

    #[DataProvider('realInserts')]
    public function testAnInsertSelectCountsTheRowsItReads(string $fixture, Engine $engine): void
    {
        $summary = (new WritePlanAdvisor())->advise($this->real($fixture, $engine), $engine);

        self::assertNotNull($summary);
        self::assertSame('insert', $summary->operation);
        self::assertSame('categories', $summary->relation);
        self::assertSame(400, $summary->rows);
        self::assertFalse($summary->fullScan);
        self::assertNull($summary->index);
        self::assertFalse($summary->locksWide);
    }

    public function testAReadIsNotAWrite(): void
    {
        self::assertNull((new WritePlanAdvisor())->advise($this->real('mysql-scan', Engine::MySql), Engine::MySql));
        self::assertNull((new WritePlanAdvisor())->advise($this->real('postgres-scan', Engine::PostgreSql), Engine::PostgreSql));
    }

    public function testASmallTableNeverLocksWideNorAsksForBatches(): void
    {
        $plan = new QueryPlan(new PlanNode(PlanKind::FullScan, 'ALL', 'categories', rows: 24, fullScan: true, writes: 'delete'), null);

        $summary = (new WritePlanAdvisor())->advise($plan, Engine::MySql);

        self::assertNotNull($summary);
        self::assertFalse($summary->locksWide);
        self::assertFalse($summary->batchAdvised);
    }

    public function testNoEstimateMeansNoVerdict(): void
    {
        $plan = new QueryPlan(new PlanNode(PlanKind::FullScan, 'ALL', 'orders', rows: null, fullScan: true, writes: 'update'), null);

        $summary = (new WritePlanAdvisor())->advise($plan, Engine::MariaDb);

        self::assertNotNull($summary);
        self::assertNull($summary->rows);
        self::assertFalse($summary->locksWide);
        self::assertFalse($summary->batchAdvised);
    }

    public function testManyRowsAdviseBatches(): void
    {
        $plan = new QueryPlan(new PlanNode(PlanKind::IndexScan, 'range', 'logs', 'idx_day', 250000, writes: 'delete'), null);

        $summary = (new WritePlanAdvisor())->advise($plan, Engine::MySql);

        self::assertNotNull($summary);
        self::assertTrue($summary->batchAdvised);
        self::assertFalse($summary->locksWide);
    }

    public function testAFullScanOfABigTableLocksWideOnMySqlAndMariaDbOnly(): void
    {
        $plan = new QueryPlan(new PlanNode(PlanKind::FullScan, 'ALL', 'logs', rows: 1000, fullScan: true, writes: 'update'), null);

        self::assertTrue((new WritePlanAdvisor())->advise($plan, Engine::MySql)?->locksWide);
        self::assertTrue((new WritePlanAdvisor())->advise($plan, Engine::MariaDb)?->locksWide);
        self::assertFalse((new WritePlanAdvisor())->advise($plan, Engine::PostgreSql)?->locksWide);
    }

    public function testAnInsertNeverLocksWide(): void
    {
        $source = new PlanNode(PlanKind::FullScan, 'ALL', 'logs', rows: 50000, fullScan: true);
        $plan = new QueryPlan(new PlanNode(PlanKind::Other, 'Insert', 'archive', children: [$source], writes: 'insert'), null);

        $summary = (new WritePlanAdvisor())->advise($plan, Engine::MySql);

        self::assertNotNull($summary);
        self::assertFalse($summary->locksWide);
        self::assertTrue($summary->batchAdvised);
        self::assertSame(50000, $summary->rows);
    }
}
