<?php

declare(strict_types=1);

namespace App\Tests\Core\Optimizer;

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

    public function testMySql26FullScanInJsonFormatV2(): void
    {
        $plan = (new MySqlPlanParser())->parse($this->fixture('mysql26-scan'));

        self::assertEqualsWithDelta(0.45, $plan->totalCost, 0.001);
        $scan = $plan->root->children[0];
        self::assertSame(PlanKind::FullScan, $scan->kind);
        self::assertTrue($scan->fullScan);
        self::assertSame('i', $scan->relation);
        self::assertSame(2, $scan->rows);
        self::assertSame('(i.q > 3)', $scan->condition);
        self::assertSame([], $scan->children);
    }

    public function testMySql26JoinInJsonFormatV2(): void
    {
        $plan = (new MySqlPlanParser())->parse($this->fixture('mysql26-join'));

        self::assertEqualsWithDelta(0.8, $plan->totalCost, 0.001);
        $join = $plan->root->children[0];
        self::assertSame(PlanKind::Join, $join->kind);
        self::assertSame(['full_scan', 'index_scan'], self::kinds($join->children));
        [$o, $i] = $join->children;
        self::assertSame('o', $o->relation);
        self::assertSame('(o.c = 1)', $o->condition);
        self::assertSame('i', $i->relation);
        self::assertSame('o_id', $i->index);
        self::assertSame(1, $i->rows);
        self::assertFalse($i->fullScan);
    }

    public function testMySql26SortAndGroupWrapTheIndexScan(): void
    {
        $plan = (new MySqlPlanParser())->parse($this->fixture('mysql26-group'));

        self::assertEqualsWithDelta(0.91, $plan->totalCost, 0.01, 'le tri ne donne pas de coût : celui de son entrée');
        $sort = $plan->root->children[0];
        self::assertSame(PlanKind::Sort, $sort->kind);
        $stream = $sort->children[0];
        self::assertSame(PlanKind::Other, $stream->kind);
        $group = $stream->children[0];
        self::assertSame(PlanKind::Aggregate, $group->kind);
        $scan = $group->children[0];
        self::assertSame(PlanKind::IndexFullScan, $scan->kind);
        self::assertSame('o_id', $scan->index);
        self::assertFalse($scan->fullScan);
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
}
