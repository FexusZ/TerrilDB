<?php

declare(strict_types=1);

namespace App\Tests\Core\Optimizer;

use App\Core\Connection\Engine;
use App\Core\Optimizer\MariaDbAnalyzeParser;
use App\Core\Optimizer\MySqlTreePlanParser;
use App\Core\Optimizer\PlanAnalyzer;
use App\Core\Optimizer\PlanKind;
use App\Core\Optimizer\PlanNode;
use App\Core\Optimizer\PostgreSqlPlanParser;
use App\Core\Optimizer\QueryPlan;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class MeasuredPlanAnalyzerTest extends TestCase
{
    private static function scan(?int $estimated, ?int $actual, string $relation = 'orders'): QueryPlan
    {
        return new QueryPlan(
            new PlanNode(PlanKind::FullScan, 'Seq Scan', $relation, rows: $estimated, fullScan: true, actualRows: $actual, loops: 1),
            null,
            true,
        );
    }

    /**
     * @return iterable<string, array{?int, ?int, bool}>
     */
    public static function estimates(): iterable
    {
        yield 'a hundred times too low' => [10, 1000, true];
        yield 'far lower' => [1, 3000, true];
        yield 'zero estimated' => [0, 5000, true];
        yield 'a hundred times too high' => [100_000, 1000, true];
        yield 'just under the factor' => [11, 1000, false];
        yield 'factor reached but few real rows' => [1, 999, false];
        yield 'close' => [1000, 1200, false];
        yield 'estimate unknown' => [null, 5000, false];
        yield 'real count unknown' => [10, null, false];
        yield 'large overestimate of a small result' => [1_000_000, 50, false];
    }

    #[DataProvider('estimates')]
    public function testStaleEstimateNeedsBothNumbersAndALargeGap(?int $estimated, ?int $actual, bool $flagged): void
    {
        $findings = (new PlanAnalyzer())->analyze(self::scan($estimated, $actual));
        $stale = array_values(array_filter($findings, static fn ($f): bool => 'stale_estimate' === $f->rule));

        self::assertCount($flagged ? 1 : 0, $stale);
        if ($flagged) {
            self::assertSame('orders', $stale[0]->relation);
            self::assertSame($estimated, $stale[0]->rows);
            self::assertSame($actual, $stale[0]->actualRows);
        }
    }

    public function testAnEstimateWithoutMeasureIsNotJudged(): void
    {
        $plan = new QueryPlan(new PlanNode(PlanKind::IndexScan, 'Index Scan', 'orders', rows: 1), null);

        self::assertSame([], (new PlanAnalyzer())->analyze($plan));
    }

    public function testOnlyAnAccessToATableIsFlaggedOnceByTable(): void
    {
        $a = new PlanNode(PlanKind::IndexScan, 'Index Scan', 'orders', rows: 1, actualRows: 5000);
        $b = new PlanNode(PlanKind::IndexScan, 'Index Scan', 'orders', rows: 1, actualRows: 7000);
        $sort = new PlanNode(PlanKind::Sort, 'Sort', rows: 1, actualRows: 9000);
        $plan = new QueryPlan(new PlanNode(PlanKind::Join, 'Nested Loop', children: [$sort, $a, $b]), null, true);

        $findings = (new PlanAnalyzer())->analyze($plan);

        self::assertCount(1, $findings);
        self::assertSame('stale_estimate', $findings[0]->rule);
        self::assertSame(5000, $findings[0]->actualRows);
    }

    public function testThePostgreSqlSuggestionIsAnAnalyzeOfTheTableToReadNotToRun(): void
    {
        $findings = (new PlanAnalyzer())->analyze(self::scan(1, 5000, 'we"ird'), Engine::PostgreSql);

        self::assertSame('ANALYZE "we""ird";', $findings[0]->suggestion);
    }

    public function testTheMySqlSuggestionNamesARealTable(): void
    {
        $findings = (new PlanAnalyzer())->analyze(self::scan(1, 5000), Engine::MySql, 'select * from orders where status = 1');

        self::assertSame('ANALYZE TABLE `orders`;', $findings[0]->suggestion);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function aliased(): iterable
    {
        yield 'alias' => ['select * from orders o join customers c on c.id = o.customer_id'];
        yield 'as alias' => ['select * from shop.orders as o'];
        yield 'quoted alias' => ['select * from `orders` `o`'];
        yield 'comma' => ['select * from customers c, orders o where c.id = o.customer_id'];
    }

    #[DataProvider('aliased')]
    public function testNoSuggestionWhenTheNameIsAnAliasOrUnverifiable(string $sql): void
    {
        $alias = (new PlanAnalyzer())->analyze(self::scan(1, 5000, 'o'), Engine::MariaDb, $sql);
        self::assertNull($alias[0]->suggestion);
    }

    public function testNoSuggestionWithoutTheStatementOrTheEngine(): void
    {
        self::assertNull((new PlanAnalyzer())->analyze(self::scan(1, 5000), Engine::MySql)[0]->suggestion);
        self::assertNull((new PlanAnalyzer())->analyze(self::scan(1, 5000))[0]->suggestion);
    }

    public function testASortOnDiskIsReported(): void
    {
        $sort = new PlanNode(PlanKind::Sort, 'Sort', rows: 7000, actualRows: 7000, sortsOnDisk: true, children: [new PlanNode(PlanKind::FullScan, 'Seq Scan', 'order_items', rows: 7000, fullScan: true, actualRows: 7000)]);

        $findings = (new PlanAnalyzer())->analyze(new QueryPlan($sort, null, true));

        $rules = array_map(static fn ($f): string => $f->rule, $findings);
        self::assertContains('sort_on_disk', $rules);
        self::assertContains('full_scan', $rules);
        self::assertNotContains('stale_estimate', $rules);
        $disk = array_values(array_filter($findings, static fn ($f): bool => 'sort_on_disk' === $f->rule))[0];
        self::assertNull($disk->relation);
        self::assertSame(7000, $disk->actualRows);
        self::assertNull($disk->suggestion);
    }

    public function testRealPostgreSqlSortOnDisk(): void
    {
        $plan = (new PostgreSqlPlanParser())->parseMeasured((string) file_get_contents(__DIR__.'/../../Fixtures/plans/postgres-measured-sort-disk.json'));

        $rules = array_map(static fn ($f): string => $f->rule, (new PlanAnalyzer())->analyze($plan, Engine::PostgreSql));

        self::assertContains('sort_on_disk', $rules);
        self::assertNotContains('stale_estimate', $rules);
    }

    public function testRealMariaDbSortOnDisk(): void
    {
        $plan = (new MariaDbAnalyzeParser())->parseMeasured((string) file_get_contents(__DIR__.'/../../Fixtures/plans/mariadb-measured-sort-disk.json'));

        $rules = array_map(static fn ($f): string => $f->rule, (new PlanAnalyzer())->analyze($plan, Engine::MariaDb, 'select * from order_items order by unit_price_cents desc'));

        self::assertContains('sort_on_disk', $rules);
    }

    public function testRealMySqlStaleStatistics(): void
    {
        $plan = (new MySqlTreePlanParser())->parseMeasured((string) file_get_contents(__DIR__.'/../../Fixtures/plans/mysql-measured-join.txt'));

        $findings = (new PlanAnalyzer())->analyze($plan, Engine::MySql, 'SELECT o.id FROM orders o JOIN customers c ON c.id = o.customer_id');

        $stale = array_values(array_filter($findings, static fn ($f): bool => 'stale_estimate' === $f->rule));
        self::assertCount(1, $stale);
        self::assertSame('o', $stale[0]->relation);
        self::assertSame(1, $stale[0]->rows);
        self::assertSame(3000, $stale[0]->actualRows);
        self::assertNull($stale[0]->suggestion, 'o is an alias');
    }

    public function testTheEstimateOnlyAnalysisIsUnchanged(): void
    {
        $big = new PlanNode(PlanKind::FullScan, 'Seq Scan', 'orders', rows: 50000, fullScan: true);

        $findings = (new PlanAnalyzer())->analyze(new QueryPlan($big, null));

        self::assertCount(1, $findings);
        self::assertSame('full_scan', $findings[0]->rule);
        self::assertNull($findings[0]->actualRows);
        self::assertNull($findings[0]->suggestion);
    }
}
