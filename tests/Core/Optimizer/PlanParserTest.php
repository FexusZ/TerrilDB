<?php

declare(strict_types=1);

namespace App\Tests\Core\Optimizer;

use App\Core\Optimizer\ClickHousePlanParser;
use App\Core\Optimizer\Exception\PlanNotAvailableException;
use App\Core\Optimizer\Exception\UnreadablePlanException;
use App\Core\Optimizer\MySqlPlanParser;
use App\Core\Optimizer\PlanKind;
use App\Core\Optimizer\PlanNode;
use App\Core\Optimizer\PostgreSqlPlanParser;
use PHPUnit\Framework\Attributes\DataProvider;
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

    public function testMySql26TemporaryTableIsNotAFullScanOfARealTable(): void
    {
        $plan = (new MySqlPlanParser())->parse($this->fixture('mysql26-union'));

        $temporary = $plan->root->children[0];
        self::assertSame(PlanKind::Other, $temporary->kind);
        self::assertFalse($temporary->fullScan);
        self::assertNull($temporary->relation);
        self::assertCount(1, $temporary->children, 'la matérialisation de l\'union est gardée');
        $scans = [];
        $walk = static function (PlanNode $n) use (&$walk, &$scans): void {
            if ($n->fullScan) {
                $scans[] = $n->relation;
            }
            array_map($walk, $n->children);
        };
        $walk($plan->root);
        sort($scans);
        self::assertSame(['i', 'o', 'o'], $scans);
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

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function writePlans(): iterable
    {
        yield 'MySQL delete' => ['mysql-write-delete-scan', 'delete', 'order_items'];
        yield 'MySQL update' => ['mysql-write-update-key', 'update', 'orders'];
        yield 'MariaDB delete' => ['mariadb-write-delete-scan', 'delete', 'order_items'];
        yield 'MariaDB update' => ['mariadb-write-update-key', 'update', 'orders'];
        yield 'PostgreSQL delete' => ['postgres-write-delete-scan', 'delete', 'order_items'];
        yield 'PostgreSQL update' => ['postgres-write-update-key', 'update', 'orders'];
        yield 'MySQL insert' => ['mysql-write-insert-select', 'insert', 'categories'];
        yield 'MySQL 26 insert' => ['mysql26-write-insert-select', 'insert', 'categories'];
        yield 'PostgreSQL insert' => ['postgres-write-insert-select', 'insert', 'categories'];
    }

    #[DataProvider('writePlans')]
    public function testAWritePlanMarksItsWriteNode(string $fixture, string $operation, string $relation): void
    {
        $parser = str_starts_with($fixture, 'postgres') ? new PostgreSqlPlanParser() : new MySqlPlanParser();

        $nodes = self::writeNodes($parser->parse($this->fixture($fixture))->root);

        self::assertCount(1, $nodes);
        self::assertSame($operation, $nodes[0]->writes);
        self::assertSame($relation, $nodes[0]->relation);
    }

    /**
     * @return list<PlanNode>
     */
    private static function writeNodes(PlanNode $node): array
    {
        $found = null === $node->writes ? [] : [$node];
        foreach ($node->children as $child) {
            array_push($found, ...self::writeNodes($child));
        }

        return $found;
    }

    public function testReadsAreNotWrites(): void
    {
        foreach (['mysql-scan', 'mysql26-scan', 'mariadb-scan', 'mysql-join', 'mysql26-join'] as $name) {
            self::assertSame([], self::writeNodes((new MySqlPlanParser())->parse($this->fixture($name))->root), $name);
        }
        self::assertSame([], self::writeNodes((new PostgreSqlPlanParser())->parse($this->fixture('postgres-join'))->root));
    }

    public function testMySqlDeleteIsAFullScanOfTheWrittenTable(): void
    {
        $node = self::writeNodes((new MySqlPlanParser())->parse($this->fixture('mysql-write-delete-scan'))->root)[0];

        self::assertSame(PlanKind::FullScan, $node->kind);
        self::assertTrue($node->fullScan);
        self::assertSame(7000, $node->rows);
        self::assertSame('(`shop`.`order_items`.`quantity` = 3)', $node->condition);
    }

    public function testMariaDbUpdateByKeyUsesThePrimaryKey(): void
    {
        $node = self::writeNodes((new MySqlPlanParser())->parse($this->fixture('mariadb-write-update-key'))->root)[0];

        self::assertSame(PlanKind::IndexScan, $node->kind);
        self::assertFalse($node->fullScan);
        self::assertSame('PRIMARY', $node->index);
        self::assertSame(1, $node->rows);
    }

    public function testAnInsertSelectKeepsItsSourceUnderTheWriteNode(): void
    {
        foreach (['mysql-write-insert-select', 'mysql26-write-insert-select'] as $name) {
            $node = self::writeNodes((new MySqlPlanParser())->parse($this->fixture($name))->root)[0];

            self::assertSame(PlanKind::Other, $node->kind, $name);
            self::assertFalse($node->fullScan, $name);
            self::assertCount(1, $node->children, $name);
            self::assertSame('products', $node->children[0]->relation, $name);
            self::assertSame(400, $node->children[0]->rows, $name);
        }
    }

    public function testPostgreSqlModifyTableHasNoRowEstimateOfItsOwn(): void
    {
        $node = self::writeNodes((new PostgreSqlPlanParser())->parse($this->fixture('postgres-write-delete-scan'))->root)[0];

        self::assertNull($node->rows);
        self::assertSame('ModifyTable', $node->label);
        self::assertSame('Seq Scan', $node->children[0]->label);
        self::assertSame(1400, $node->children[0]->rows);
    }

    public function testMySqlIteratorPlanCannotExplainAnUpdateOrDelete(): void
    {
        foreach (['mysql26-write-delete-scan', 'mysql26-write-update-key'] as $name) {
            try {
                (new MySqlPlanParser())->parse($this->fixture($name));
                self::fail('Expected no plan.');
            } catch (PlanNotAvailableException) {
            }
        }
        $this->addToAssertionCount(1);
    }
}
