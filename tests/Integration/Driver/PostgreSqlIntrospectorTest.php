<?php

declare(strict_types=1);

namespace App\Tests\Integration\Driver;

use App\Core\Driver\Exception\TargetConnectionFailedException;
use App\Core\Schema\Exception\TableNotFoundException;
use App\Core\Schema\ForeignKeyDefinition;
use App\Core\Schema\IndexDefinition;
use App\Core\Schema\ReferentialAction;
use App\Core\Schema\TableKind;
use App\Driver\DbalConnector;
use App\Driver\PostgreSql\PostgreSqlIntrospector;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('integration')]
final class PostgreSqlIntrospectorTest extends TestCase
{
    private const TARGET = 'TARGET_POSTGRES_URL';

    private function introspector(): PostgreSqlIntrospector
    {
        try {
            return new PostgreSqlIntrospector((new DbalConnector())->connect(TargetDatabase::engine(self::TARGET), TargetDatabase::parameters(self::TARGET)));
        } catch (TargetConnectionFailedException) {
            self::markTestSkipped('The PostgreSQL target is unreachable (docker compose --profile targets up -d --wait).');
        }
    }

    /**
     * @param list<IndexDefinition> $indexes
     *
     * @return list<string>
     */
    private static function indexNames(array $indexes): array
    {
        return array_map(static fn (IndexDefinition $i): string => $i->name, $indexes);
    }

    public function testListsTablesAndViewsSortedByName(): void
    {
        $tables = $this->introspector()->listTables();

        self::assertSame(
            ['categories', 'customers', 'order_items', 'orders', 'products', 'v_order_totals', 'v_product_sales'],
            array_map(static fn ($t): string => $t->name, $tables),
        );
        self::assertSame(
            [TableKind::Table, TableKind::Table, TableKind::Table, TableKind::Table, TableKind::Table, TableKind::View, TableKind::View],
            array_map(static fn ($t): TableKind => $t->kind, $tables),
        );
    }

    public function testRowCountsAreEstimatesForTablesAndAbsentForViews(): void
    {
        foreach ($this->introspector()->listTables() as $table) {
            if (TableKind::View === $table->kind) {
                self::assertNull($table->estimatedRows, $table->name);
            } else {
                // -1 (jamais analysée) devient « pas d'estimation », jamais un nombre négatif.
                self::assertGreaterThanOrEqual(0, $table->estimatedRows ?? 0, $table->name);
            }
        }
    }

    public function testDescribesColumnsInOrderWithNativeTypes(): void
    {
        $orders = $this->introspector()->describeTable('orders');

        self::assertSame('orders', $orders->name);
        self::assertSame(TableKind::Table, $orders->kind);
        self::assertSame(['id', 'customer_id', 'status', 'ordered_at'], array_map(static fn ($c): string => $c->name, $orders->columns));
        $id = $orders->column('id');
        self::assertNotNull($id);
        self::assertSame('integer', $id->type);
        self::assertFalse($id->nullable);
        self::assertTrue($id->autoIncrement);
        $status = $orders->column('status');
        self::assertNotNull($status);
        self::assertSame('character varying(20)', $status->type);
        self::assertSame('timestamp without time zone', $orders->column('ordered_at')?->type);
        self::assertFalse($status->autoIncrement);
        self::assertNull($status->default);
        self::assertNull($status->comment);
    }

    public function testANullableColumnWithoutDefaultHasNoDefault(): void
    {
        $parent = $this->introspector()->describeTable('categories')->column('parent_id');

        self::assertNotNull($parent);
        self::assertTrue($parent->nullable);
        self::assertNull($parent->default);
    }

    public function testDescribesIndexesPrimaryFirstThenByName(): void
    {
        $orders = $this->introspector()->describeTable('orders');

        self::assertSame(['orders_pkey', 'idx_orders_customer_date', 'idx_orders_status'], self::indexNames($orders->indexes));
        $primary = $orders->primaryKey();
        self::assertNotNull($primary);
        self::assertSame(['id'], $primary->columns);
        self::assertTrue($primary->unique);
        $composite = $orders->indexes[1];
        self::assertSame(['customer_id', 'ordered_at'], $composite->columns);
        self::assertFalse($composite->unique);
        self::assertFalse($composite->primary);
    }

    public function testUniqueConstraintsAppearAsUniqueIndexes(): void
    {
        $items = $this->introspector()->describeTable('order_items');

        $unique = array_values(array_filter($items->indexes, static fn (IndexDefinition $i): bool => $i->unique && !$i->primary));
        self::assertSame(['uq_order_items_line'], self::indexNames($unique));
        self::assertSame(['order_id', 'line_no'], $unique[0]->columns);
        self::assertSame('smallint', $items->column('line_no')?->type);
    }

    public function testDescribesForeignKeysSortedByName(): void
    {
        $items = $this->introspector()->describeTable('order_items');

        self::assertSame(['fk_order_items_order', 'fk_order_items_product'], array_map(static fn (ForeignKeyDefinition $f): string => $f->name, $items->foreignKeys));
        [$order, $product] = $items->foreignKeys;
        self::assertSame(['order_id'], $order->columns);
        self::assertSame('orders', $order->referencedTable);
        self::assertSame(['id'], $order->referencedColumns);
        self::assertSame('products', $product->referencedTable);
        self::assertSame(ReferentialAction::NoAction, $order->onDelete);
        self::assertSame(ReferentialAction::NoAction, $order->onUpdate);
    }

    public function testASelfReferencingKeyIsDescribed(): void
    {
        $categories = $this->introspector()->describeTable('categories');

        self::assertCount(1, $categories->foreignKeys);
        self::assertSame('categories', $categories->foreignKeys[0]->referencedTable);
        self::assertSame(['parent_id'], $categories->foreignKeys[0]->columns);
        self::assertSame(['id'], $categories->foreignKeys[0]->referencedColumns);
    }

    public function testATableWithoutForeignKeysHasNone(): void
    {
        self::assertSame([], $this->introspector()->describeTable('customers')->foreignKeys);
    }

    public function testDescribesAViewWithItsDefinition(): void
    {
        $view = $this->introspector()->describeTable('v_order_totals');

        self::assertSame(TableKind::View, $view->kind);
        self::assertSame(['order_id', 'customer_id', 'status', 'item_count', 'total_cents'], array_map(static fn ($c): string => $c->name, $view->columns));
        self::assertNotNull($view->viewDefinition);
        self::assertStringContainsString('order_items', $view->viewDefinition);
        self::assertSame([], $view->indexes);
        self::assertSame([], $view->foreignKeys);
        self::assertNull($view->estimatedRows);
    }

    public function testATableHasNoViewDefinition(): void
    {
        self::assertNull($this->introspector()->describeTable('orders')->viewDefinition);
    }

    public function testAnUnknownTableIsReported(): void
    {
        $this->expectException(TableNotFoundException::class);

        $this->introspector()->describeTable('no_such_table');
    }

    public function testTheTableNameIsNeverInterpolated(): void
    {
        $introspector = $this->introspector();

        try {
            $introspector->describeTable("orders' OR '1'='1");
            self::fail('Expected a refusal.');
        } catch (TableNotFoundException) {
            self::assertCount(7, $introspector->listTables());
        }
    }

    public function testTheNameIsMatchedExactly(): void
    {
        $this->expectException(TableNotFoundException::class);

        $this->introspector()->describeTable('ORDERS');
    }
}
