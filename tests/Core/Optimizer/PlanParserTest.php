<?php

declare(strict_types=1);

namespace App\Tests\Core\Optimizer;

use App\Core\Optimizer\ClickHousePlanParser;
use App\Core\Optimizer\Exception\UnreadablePlanException;
use App\Core\Optimizer\MySqlPlanParser;
use App\Core\Optimizer\PlanKind;
use App\Core\Optimizer\PlanNode;
use App\Core\Optimizer\PostgreSqlPlanParser;
use PHPUnit\Framework\TestCase;

final class PlanParserTest extends TestCase
{
    private function fixture(string $name): string
    {
        return (string) file_get_contents(__DIR__.'/../../Fixtures/plans/'.$name.'.json');
    }

    /**
     * @param list<PlanNode> $nodes
     *
     * @return list<string>
     */
    private static function kinds(array $nodes): array
    {
        return array_map(static fn (PlanNode $node): string => $node->kind->value, $nodes);
    }

    public function testPostgreSqlJoinTree(): void
    {
        $plan = (new PostgreSqlPlanParser())->parse($this->fixture('postgres-join'));

        self::assertSame(PlanKind::Limit, $plan->root->kind);
        self::assertSame('Limit', $plan->root->label);
        self::assertEqualsWithDelta(2.94, $plan->totalCost, 0.001);
        $join = $plan->root->children[0];
        self::assertSame(PlanKind::Join, $join->kind);
        self::assertSame('Nested Loop', $join->label);
        self::assertCount(2, $join->children);

        $orders = $join->children[0];
        self::assertSame(PlanKind::IndexScan, $orders->kind);
        self::assertSame('orders', $orders->relation);
        self::assertSame('orders_pkey', $orders->index);
        self::assertSame(750, $orders->rows);
        self::assertEqualsWithDelta(119.78, $orders->cost, 0.001);
        self::assertSame("((status)::text = 'shipped'::text)", $orders->condition);
        self::assertFalse($orders->fullScan);

        $memoize = $join->children[1];
        self::assertSame(PlanKind::Other, $memoize->kind);
        self::assertSame('Memoize', $memoize->label);
        self::assertSame(PlanKind::IndexScan, $memoize->children[0]->kind);
        self::assertSame('customers', $memoize->children[0]->relation);
    }

    public function testPostgreSqlFullScan(): void
    {
        $plan = (new PostgreSqlPlanParser())->parse($this->fixture('postgres-scan'));

        self::assertSame(PlanKind::FullScan, $plan->root->kind);
        self::assertTrue($plan->root->fullScan);
        self::assertSame('order_items', $plan->root->relation);
        self::assertSame(2800, $plan->root->rows);
        self::assertSame('(quantity > 3)', $plan->root->condition);
        self::assertNull($plan->root->index);
        self::assertSame([], $plan->root->children);
    }

    public function testMySqlJoinTree(): void
    {
        $plan = (new MySqlPlanParser())->parse($this->fixture('mysql-join'));

        self::assertSame(PlanKind::Other, $plan->root->kind);
        self::assertEqualsWithDelta(1.15, $plan->totalCost, 0.001);
        $sort = $plan->root->children[0];
        self::assertSame(PlanKind::Sort, $sort->kind);
        $join = $sort->children[0];
        self::assertSame(PlanKind::Join, $join->kind);
        self::assertSame(['index_full_scan', 'index_scan'], self::kinds($join->children));

        [$o, $c] = $join->children;
        self::assertSame('o', $o->relation);
        self::assertSame('PRIMARY', $o->index);
        self::assertSame('index', $o->label);
        self::assertFalse($o->fullScan);
        self::assertSame("(`shop`.`o`.`status` = 'shipped')", $o->condition);
        self::assertSame('eq_ref', $c->label);
        self::assertSame(1, $c->rows);
    }

    public function testMySqlFullScan(): void
    {
        $plan = (new MySqlPlanParser())->parse($this->fixture('mysql-scan'));

        $scan = $plan->root->children[0];
        self::assertSame(PlanKind::FullScan, $scan->kind);
        self::assertTrue($scan->fullScan);
        self::assertSame('order_items', $scan->relation);
        self::assertSame('(`shop`.`order_items`.`quantity` > 3)', $scan->condition);
    }

    public function testMariaDbJoinTreeCollapsesTheLoneNestedLoop(): void
    {
        $plan = (new MySqlPlanParser())->parse($this->fixture('mariadb-join'));

        self::assertEqualsWithDelta(1.1832932, $plan->totalCost, 0.0001);
        $join = $plan->root->children[0];
        self::assertSame(PlanKind::Join, $join->kind);
        [$o, $c] = $join->children;
        self::assertSame(PlanKind::IndexScan, $o->kind);
        self::assertSame('idx_orders_status', $o->index);
        self::assertSame(750, $o->rows);
        self::assertSame("o.`status` = 'shipped'", $o->condition);
        self::assertSame('c', $c->relation);
    }

    public function testMariaDbSingleTableIsNotWrappedInAJoin(): void
    {
        $plan = (new MySqlPlanParser())->parse($this->fixture('mariadb-scan'));

        self::assertSame(PlanKind::FullScan, $plan->root->children[0]->kind);
        self::assertSame('order_items', $plan->root->children[0]->relation);
    }

    public function testAQueryWithoutTablesStillHasARoot(): void
    {
        $plan = (new MySqlPlanParser())->parse('{"query_block": {"select_id": 1, "message": "No tables used"}}');

        self::assertSame([], $plan->root->children);
        self::assertSame('No tables used', $plan->root->label);
    }

    public function testGroupingAndDuplicateRemovalAreOperations(): void
    {
        $json = '{"query_block": {"select_id": 1, "grouping_operation": {"using_filesort": false, "table": {"table_name": "t", "access_type": "ALL", "rows_examined_per_scan": 5000}}}}';

        $plan = (new MySqlPlanParser())->parse($json);

        self::assertSame(PlanKind::Aggregate, $plan->root->children[0]->kind);
        self::assertSame(PlanKind::FullScan, $plan->root->children[0]->children[0]->kind);
    }

    public function testRejectsWhatIsNotAPlan(): void
    {
        foreach (['', 'not json', '[]', '{}', '"text"', '[{"nope": 1}]'] as $garbage) {
            foreach ([new PostgreSqlPlanParser(), new MySqlPlanParser()] as $parser) {
                try {
                    $parser->parse($garbage);
                    self::fail('Expected a refusal for: '.$garbage);
                } catch (UnreadablePlanException) {
                }
            }
        }
        $this->addToAssertionCount(1);
    }

    public function testToleratesMissingNumbers(): void
    {
        $plan = (new PostgreSqlPlanParser())->parse('[{"Plan": {"Node Type": "Result"}}]');

        self::assertNull($plan->root->rows);
        self::assertNull($plan->root->cost);
        self::assertNull($plan->totalCost);
    }

    public function testClickHouseReadWithoutUsableKeyIsAFullScan(): void
    {
        $plan = (new ClickHousePlanParser())->parse($this->fixture('clickhouse-group'));

        self::assertNull($plan->totalCost);
        self::assertSame(PlanKind::Other, $plan->root->kind);
        $aggregate = $plan->root->children[0];
        self::assertSame(PlanKind::Aggregate, $aggregate->kind);
        self::assertSame('Aggregating', $aggregate->label);

        $read = $aggregate->children[0]->children[0]->children[0];
        self::assertSame(PlanKind::FullScan, $read->kind);
        self::assertSame('orders', $read->relation);
        self::assertNull($read->index);
        self::assertTrue($read->fullScan);
        self::assertNull($read->rows);
    }

    public function testClickHouseReadNarrowedBySortingKeyIsAnIndexScan(): void
    {
        $plan = (new ClickHousePlanParser())->parse($this->fixture('clickhouse-key'));

        $limit = $plan->root->children[0]->children[0]->children[0];
        self::assertSame(PlanKind::Limit, $limit->kind);
        $sort = $limit->children[0];
        self::assertSame(PlanKind::Sort, $sort->kind);

        $read = $sort->children[0]->children[0]->children[0];
        self::assertSame(PlanKind::IndexScan, $read->kind);
        self::assertSame('orders', $read->relation);
        self::assertSame('PRIMARY KEY', $read->index);
        self::assertSame('(customer_id in [5, 5])', $read->condition);
        self::assertFalse($read->fullScan);
    }

    public function testClickHouseRejectsWhatIsNotAPlan(): void
    {
        $this->expectException(UnreadablePlanException::class);

        (new ClickHousePlanParser())->parse('[{"Nope": 1}]');
    }
}
