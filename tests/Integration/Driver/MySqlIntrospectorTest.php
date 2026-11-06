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
use App\Driver\MySql\MySqlIntrospector;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('integration')]
final class MySqlIntrospectorTest extends TestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function targets(): iterable
    {
        yield 'MySQL' => ['TARGET_MYSQL_URL'];
        yield 'MariaDB' => ['TARGET_MARIADB_URL'];
    }

    /**
     * Le type natif est celui que le moteur écrit : MariaDB garde la largeur d'affichage des entiers.
     *
     * @return iterable<string, array{string, string, string}>
     */
    public static function targetsWithIntegerTypes(): iterable
    {
        yield 'MySQL' => ['TARGET_MYSQL_URL', 'int unsigned', 'smallint unsigned'];
        yield 'MariaDB' => ['TARGET_MARIADB_URL', 'int(10) unsigned', 'smallint(5) unsigned'];
    }

    private function introspector(string $envVar): MySqlIntrospector
    {
        try {
            return new MySqlIntrospector((new DbalConnector())->connect(TargetDatabase::engine($envVar), TargetDatabase::parameters($envVar)));
        } catch (TargetConnectionFailedException) {
            self::markTestSkipped(\sprintf('%s is unreachable (docker compose --profile targets up -d --wait).', $envVar));
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

    #[DataProvider('targets')]
    public function testListsTheDatabasesTheAccountCanSeeWithTheSystemOnesFlagged(string $envVar): void
    {
        $databases = $this->introspector($envVar)->listDatabases();

        $byName = [];
        foreach ($databases as $database) {
            $byName[$database->name] = $database->system;
        }
        self::assertArrayHasKey('shop', $byName);
        self::assertFalse($byName['shop']);
        self::assertArrayHasKey('information_schema', $byName);
        self::assertTrue($byName['information_schema']);
        $names = array_keys($byName);
        $sorted = $names;
        usort($sorted, strcmp(...));
        self::assertSame($sorted, $names, 'sorted by bytes');
    }

    #[DataProvider('targets')]
    public function testListsTablesAndViewsSortedByName(string $envVar): void
    {
        $tables = $this->introspector($envVar)->listTables();

        self::assertSame(
            ['categories', 'customers', 'order_items', 'orders', 'products', 'v_order_totals', 'v_product_sales'],
            array_map(static fn ($t): string => $t->name, $tables),
        );
        self::assertSame(
            [TableKind::Table, TableKind::Table, TableKind::Table, TableKind::Table, TableKind::Table, TableKind::View, TableKind::View],
            array_map(static fn ($t): TableKind => $t->kind, $tables),
        );
    }

    #[DataProvider('targets')]
    public function testRowCountsAreEstimatesForTablesAndAbsentForViews(string $envVar): void
    {
        foreach ($this->introspector($envVar)->listTables() as $table) {
            if (TableKind::View === $table->kind) {
                self::assertNull($table->estimatedRows, $table->name);
            } else {
                self::assertGreaterThanOrEqual(0, $table->estimatedRows ?? 0, $table->name);
            }
        }
    }

    #[DataProvider('targetsWithIntegerTypes')]
    public function testDescribesColumnsInOrderWithNativeTypes(string $envVar, string $intType, string $smallType): void
    {
        $orders = $this->introspector($envVar)->describeTable('orders');

        self::assertSame('orders', $orders->name);
        self::assertSame(TableKind::Table, $orders->kind);
        self::assertSame(['id', 'customer_id', 'status', 'ordered_at'], array_map(static fn ($c): string => $c->name, $orders->columns));
        $id = $orders->column('id');
        self::assertNotNull($id);
        self::assertSame($intType, $id->type);
        self::assertFalse($id->nullable);
        self::assertTrue($id->autoIncrement);
        $status = $orders->column('status');
        self::assertNotNull($status);
        self::assertSame('varchar(20)', $status->type);
        self::assertSame('datetime', $orders->column('ordered_at')?->type);
        self::assertFalse($status->autoIncrement);
        self::assertNull($status->default);
        self::assertNull($status->comment);
    }

    #[DataProvider('targets')]
    public function testANullableColumnWithoutDefaultHasNoDefault(string $envVar): void
    {
        $parent = $this->introspector($envVar)->describeTable('categories')->column('parent_id');

        self::assertNotNull($parent);
        self::assertTrue($parent->nullable);
        self::assertNull($parent->default);
    }

    #[DataProvider('targets')]
    public function testDescribesIndexesPrimaryFirstThenByName(string $envVar): void
    {
        $orders = $this->introspector($envVar)->describeTable('orders');

        self::assertSame(['PRIMARY', 'idx_orders_customer_date', 'idx_orders_status'], self::indexNames($orders->indexes));
        $primary = $orders->primaryKey();
        self::assertNotNull($primary);
        self::assertSame(['id'], $primary->columns);
        self::assertTrue($primary->unique);
        $composite = $orders->indexes[1];
        self::assertSame(['customer_id', 'ordered_at'], $composite->columns);
        self::assertFalse($composite->unique);
        self::assertFalse($composite->primary);
    }

    #[DataProvider('targetsWithIntegerTypes')]
    public function testUniqueIndexesAreFlagged(string $envVar, string $intType, string $smallType): void
    {
        $items = $this->introspector($envVar)->describeTable('order_items');

        $unique = array_values(array_filter($items->indexes, static fn (IndexDefinition $i): bool => $i->unique && !$i->primary));
        self::assertSame(['uq_order_items_line'], self::indexNames($unique));
        self::assertSame(['order_id', 'line_no'], $unique[0]->columns);
        self::assertSame($smallType, $items->column('line_no')?->type);
    }

    #[DataProvider('targets')]
    public function testDescribesForeignKeysSortedByName(string $envVar): void
    {
        $items = $this->introspector($envVar)->describeTable('order_items');

        self::assertSame(['fk_order_items_order', 'fk_order_items_product'], array_map(static fn (ForeignKeyDefinition $f): string => $f->name, $items->foreignKeys));
        [$order, $product] = $items->foreignKeys;
        self::assertSame(['order_id'], $order->columns);
        self::assertSame('orders', $order->referencedTable);
        self::assertSame(['id'], $order->referencedColumns);
        self::assertSame('products', $product->referencedTable);
        self::assertContains($order->onDelete, [ReferentialAction::NoAction, ReferentialAction::Restrict]);
        self::assertContains($order->onUpdate, [ReferentialAction::NoAction, ReferentialAction::Restrict]);
    }

    #[DataProvider('targets')]
    public function testASelfReferencingKeyIsDescribed(string $envVar): void
    {
        $categories = $this->introspector($envVar)->describeTable('categories');

        self::assertCount(1, $categories->foreignKeys);
        self::assertSame('categories', $categories->foreignKeys[0]->referencedTable);
        self::assertSame(['parent_id'], $categories->foreignKeys[0]->columns);
    }

    #[DataProvider('targets')]
    public function testATableWithoutForeignKeysHasNone(string $envVar): void
    {
        self::assertSame([], $this->introspector($envVar)->describeTable('customers')->foreignKeys);
    }

    #[DataProvider('targets')]
    public function testDescribesAViewWithItsDefinition(string $envVar): void
    {
        $view = $this->introspector($envVar)->describeTable('v_order_totals');

        self::assertSame(TableKind::View, $view->kind);
        self::assertSame(['order_id', 'customer_id', 'status', 'item_count', 'total_cents'], array_map(static fn ($c): string => $c->name, $view->columns));
        self::assertNotNull($view->viewDefinition);
        self::assertStringContainsString('order_items', $view->viewDefinition);
        self::assertSame([], $view->indexes);
        self::assertSame([], $view->foreignKeys);
        self::assertNull($view->estimatedRows);
    }

    #[DataProvider('targets')]
    public function testATableHasNoViewDefinition(string $envVar): void
    {
        self::assertNull($this->introspector($envVar)->describeTable('orders')->viewDefinition);
    }

    #[DataProvider('targets')]
    public function testAnUnknownTableIsReported(string $envVar): void
    {
        $this->expectException(TableNotFoundException::class);

        $this->introspector($envVar)->describeTable('no_such_table');
    }

    #[DataProvider('targets')]
    public function testTheTableNameIsNeverInterpolated(string $envVar): void
    {
        $introspector = $this->introspector($envVar);

        try {
            $introspector->describeTable("orders' OR '1'='1");
            self::fail('Expected a refusal.');
        } catch (TableNotFoundException) {
            self::assertCount(7, $introspector->listTables());
        }
    }

    public function testThereIsNoSchemaLevelBelowADatabase(): void
    {
        $this->expectException(\App\Core\Driver\Exception\UnsupportedOperationException::class);

        $this->introspector('TARGET_MYSQL_URL')->listSchemas();
    }
}
