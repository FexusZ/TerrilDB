<?php

declare(strict_types=1);

namespace App\Tests\Core\Optimizer;

use App\Core\Optimizer\Exception\UnreadablePlanException;
use App\Core\Optimizer\MariaDbAnalyzeParser;
use App\Core\Optimizer\MySqlTreePlanParser;
use App\Core\Optimizer\PlanKind;
use App\Core\Optimizer\PlanNode;
use App\Core\Optimizer\PostgreSqlPlanParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Les plans MESURÉS (EXPLAIN ANALYZE) lus depuis des sorties réelles des moteurs de test (valeurs synthétiques).
 */
final class MeasuredPlanParserTest extends TestCase
{
    private function fixture(string $name): string
    {
        $path = __DIR__.'/../../Fixtures/plans/'.$name;

        return (string) file_get_contents(is_file($path.'.json') ? $path.'.json' : $path.'.txt');
    }

    public function testPostgreSqlJoinKeepsEstimatesAndAddsTheMeasure(): void
    {
        $plan = (new PostgreSqlPlanParser())->parseMeasured($this->fixture('postgres-measured-join'));

        self::assertTrue($plan->measured);
        self::assertEqualsWithDelta(0.56, $plan->executionTimeMs, 0.0001);
        $limit = $plan->root;
        self::assertSame(PlanKind::Limit, $limit->kind);
        self::assertSame(50, $limit->actualRows);
        self::assertSame(1, $limit->loops);
        self::assertEqualsWithDelta(0.508, $limit->actualTimeMs, 0.0001);

        $sort = $limit->children[0];
        self::assertSame(750, $sort->rows, 'the estimate stays an estimate');
        self::assertSame(50, $sort->actualRows, 'a sort stopped by LIMIT returns less than estimated');
        self::assertFalse($sort->sortsOnDisk);

        $orders = $sort->children[0]->children[0];
        self::assertSame('orders', $orders->relation);
        self::assertSame(750, $orders->rows);
        self::assertSame(750, $orders->actualRows);
        self::assertNotNull($orders->buffers);
        self::assertSame(22, $orders->buffers->sharedHit);
        self::assertSame(0, $orders->buffers->sharedRead);
        self::assertSame(0, $orders->buffers->tempWritten);
    }

    public function testPostgreSqlSortThatSpillsToDisk(): void
    {
        $plan = (new PostgreSqlPlanParser())->parseMeasured($this->fixture('postgres-measured-sort-disk'));

        self::assertSame(PlanKind::Sort, $plan->root->kind);
        self::assertTrue($plan->root->sortsOnDisk);
        self::assertSame(7000, $plan->root->actualRows);
        self::assertNotNull($plan->root->buffers);
        self::assertSame(69, $plan->root->buffers->tempWritten);
        self::assertFalse($plan->root->children[0]->sortsOnDisk);
    }

    public function testAPlainPostgreSqlPlanIsNotMeasured(): void
    {
        $plan = (new PostgreSqlPlanParser())->parse($this->fixture('postgres-scan'));

        self::assertFalse($plan->measured);
        self::assertNull($plan->executionTimeMs);
        self::assertNull($plan->root->actualRows);
        self::assertNull($plan->root->buffers);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function mysqlTrees(): iterable
    {
        yield 'MySQL 8.4' => ['mysql-measured-join'];
        yield 'MySQL 26' => ['mysql26-measured-join'];
    }

    #[DataProvider('mysqlTrees')]
    public function testMySqlTreeText(string $fixture): void
    {
        $plan = (new MySqlTreePlanParser())->parseMeasured($this->fixture($fixture));

        self::assertTrue($plan->measured);
        $limit = $plan->root->children[0];
        self::assertSame(PlanKind::Limit, $limit->kind);
        self::assertSame(50, $limit->actualRows);
        self::assertSame(1, $limit->loops);
        self::assertEqualsWithDelta(0.7, $plan->totalCost, 0.0001);
        self::assertNotNull($plan->executionTimeMs);

        $join = $limit->children[0];
        self::assertSame(PlanKind::Join, $join->kind);
        [$sort, $lookup] = $join->children;
        self::assertSame(PlanKind::Sort, $sort->kind);
        self::assertSame(50, $lookup->loops);
        self::assertSame('c', $lookup->relation);
        self::assertSame('PRIMARY', $lookup->index);
        self::assertSame(1, $lookup->actualRows);
        self::assertSame(PlanKind::IndexScan, $lookup->kind);

        // Le filtre se replie dans l'accès à la table : ses chiffres sont ceux du parcours (estimé 1 ligne, 3000 lues).
        $scan = $sort->children[0];
        self::assertSame(PlanKind::FullScan, $scan->kind);
        self::assertTrue($scan->fullScan);
        self::assertSame('o', $scan->relation);
        self::assertSame(1, $scan->rows);
        self::assertSame(3000, $scan->actualRows);
        self::assertStringContainsString('shipped', (string) $scan->condition);
    }

    public function testMySqlTreeOfASort(): void
    {
        $plan = (new MySqlTreePlanParser())->parseMeasured($this->fixture('mysql26-measured-sort'));

        $sort = $plan->root->children[0];
        self::assertSame(PlanKind::Sort, $sort->kind);
        self::assertSame(7000, $sort->actualRows);
        self::assertFalse($sort->sortsOnDisk, 'MySQL does not say where a sort ran');
        self::assertSame('order_items', $sort->children[0]->relation);
    }

    public function testMySqlTreeUnderstandsScientificNotationAndNeverExecuted(): void
    {
        $text = "-> Nested loop inner join  (cost=1.2e+3 rows=2e+3) (actual time=982e-6..1e-3 rows=1 loops=2)\n    -> Table scan on t  (cost=1 rows=1) (never executed)\n";
        $plan = (new MySqlTreePlanParser())->parseMeasured($text);

        $join = $plan->root->children[0];
        self::assertSame(2000, $join->rows);
        self::assertEqualsWithDelta(0.001, $join->actualTimeMs, 1e-9);
        self::assertSame(2, $join->loops);
        self::assertSame(0, $join->children[0]->actualRows);
        self::assertSame(0, $join->children[0]->loops);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function unreadableTrees(): iterable
    {
        yield 'empty' => [''];
        yield 'json' => ['{"query_block": {}}'];
        yield 'no node' => ['nothing to see'];
    }

    #[DataProvider('unreadableTrees')]
    public function testMySqlTreeUnreadable(string $text): void
    {
        $this->expectException(UnreadablePlanException::class);

        (new MySqlTreePlanParser())->parseMeasured($text);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function mariadbJoins(): iterable
    {
        yield 'MariaDB 11.8' => ['mariadb-measured-join'];
        yield 'MariaDB 13' => ['mariadb13-measured-join'];
    }

    #[DataProvider('mariadbJoins')]
    public function testMariaDbAnalyzeJson(string $fixture): void
    {
        $plan = (new MariaDbAnalyzeParser())->parseMeasured($this->fixture($fixture));

        self::assertTrue($plan->measured);
        self::assertEqualsWithDelta(1.1832932, $plan->totalCost, 0.0001);
        self::assertNotNull($plan->executionTimeMs);
        [$sort, $lookup] = $plan->root->children;

        self::assertSame(PlanKind::Sort, $sort->kind);
        self::assertSame(750, $sort->actualRows);
        self::assertFalse($sort->sortsOnDisk);
        $orders = $sort->children[0];
        self::assertSame('o', $orders->relation);
        self::assertSame('idx_orders_status', $orders->index);
        self::assertSame(PlanKind::IndexScan, $orders->kind);
        self::assertSame(750, $orders->rows);
        self::assertSame(750, $orders->actualRows);
        self::assertStringContainsString('shipped', (string) $orders->condition);

        self::assertSame('c', $lookup->relation);
        self::assertSame(50, $lookup->loops);
        self::assertSame(1, $lookup->actualRows);
        self::assertNotNull($lookup->actualTimeMs);
    }

    public function testMariaDbSortThatNeededMergePasses(): void
    {
        $plan = (new MariaDbAnalyzeParser())->parseMeasured($this->fixture('mariadb-measured-sort-disk'));

        $sort = $plan->root->children[0];
        self::assertSame(PlanKind::Sort, $sort->kind);
        self::assertTrue($sort->sortsOnDisk);
        self::assertSame(7000, $sort->actualRows);
        $scan = $sort->children[0];
        self::assertSame(PlanKind::FullScan, $scan->kind);
        self::assertTrue($scan->fullScan);
        self::assertSame(7000, $scan->actualRows);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function unreadableJson(): iterable
    {
        yield 'not json' => ['hello'];
        yield 'no block' => ['{"a": 1}'];
        yield 'a list' => ['[]'];
    }

    #[DataProvider('unreadableJson')]
    public function testMariaDbUnreadable(string $json): void
    {
        $this->expectException(UnreadablePlanException::class);

        (new MariaDbAnalyzeParser())->parseMeasured($json);
    }

    public function testNodesCanBeBuiltWithoutAnyMeasure(): void
    {
        $node = new PlanNode(PlanKind::Other, 'x');

        self::assertNull($node->actualRows);
        self::assertNull($node->actualTimeMs);
        self::assertNull($node->loops);
        self::assertNull($node->buffers);
        self::assertFalse($node->sortsOnDisk);
    }
}
