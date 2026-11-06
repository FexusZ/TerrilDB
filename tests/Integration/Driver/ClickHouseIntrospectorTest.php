<?php

declare(strict_types=1);

namespace App\Tests\Integration\Driver;

use App\Core\Schema\Exception\TableNotFoundException;
use App\Core\Schema\IndexDefinition;
use App\Core\Schema\TableKind;
use App\Driver\ClickHouse\ClickHouseException;
use App\Driver\ClickHouse\ClickHouseHttp;
use App\Driver\ClickHouse\ClickHouseIntrospector;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\HttpClient;

#[Group('integration')]
final class ClickHouseIntrospectorTest extends TestCase
{
    private const TARGET = 'TARGET_CLICKHOUSE_URL';

    private function introspector(): ClickHouseIntrospector
    {
        $parameters = TargetDatabase::parameters(self::TARGET);
        $http = new ClickHouseHttp(HttpClient::create(), $parameters);
        try {
            $http->query('SELECT 1');
        } catch (ClickHouseException) {
            self::markTestSkipped('The ClickHouse target is unreachable (docker compose --profile targets up -d --wait).');
        }

        return new ClickHouseIntrospector($http, $parameters->database);
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

    public function testListsTheDatabasesWithTheSystemOnesFlagged(): void
    {
        $byName = [];
        foreach ($this->introspector()->listDatabases() as $database) {
            $byName[$database->name] = $database->system;
        }

        self::assertFalse($byName['shop'] ?? null);
        self::assertTrue($byName['system'] ?? null);
        self::assertTrue($byName['information_schema'] ?? null);
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

    public function testRowCountsComeFromTheEngineAndAreAbsentForViews(): void
    {
        $counts = [];
        foreach ($this->introspector()->listTables() as $table) {
            $counts[$table->name] = $table->estimatedRows;
        }

        self::assertSame(7000, $counts['order_items']);
        self::assertSame(1000, $counts['customers']);
        self::assertNull($counts['v_order_totals']);
    }

    public function testDescribesColumnsInOrderWithNativeTypes(): void
    {
        $orders = $this->introspector()->describeTable('orders');

        self::assertSame('orders', $orders->name);
        self::assertSame(TableKind::Table, $orders->kind);
        self::assertSame(['id', 'customer_id', 'status', 'ordered_at'], array_map(static fn ($c): string => $c->name, $orders->columns));
        $status = $orders->column('status');
        self::assertNotNull($status);
        self::assertSame('LowCardinality(String)', $status->type);
        self::assertFalse($status->nullable);
        self::assertFalse($status->autoIncrement);
        $parent = $this->introspector()->describeTable('categories')->column('parent_id');
        self::assertNotNull($parent);
        self::assertSame('Nullable(UInt32)', $parent->type);
        self::assertTrue($parent->nullable);
    }

    public function testTheSortingKeyIsListedAsANonUniqueIndex(): void
    {
        $orders = $this->introspector()->describeTable('orders');

        self::assertSame(['ORDER BY'], self::indexNames($orders->indexes));
        self::assertSame(['customer_id', 'ordered_at'], $orders->indexes[0]->columns);
        self::assertFalse($orders->indexes[0]->unique);
        self::assertFalse($orders->indexes[0]->primary);
        self::assertNull($orders->primaryKey());
    }

    public function testDataSkippingIndexesFollowTheSortingKey(): void
    {
        $items = $this->introspector()->describeTable('order_items');

        self::assertSame(['ORDER BY', 'idx_order_items_product'], self::indexNames($items->indexes));
        self::assertSame(['order_id', 'line_no'], $items->indexes[0]->columns);
        self::assertSame(['product_id'], $items->indexes[1]->columns);
    }

    public function testThereAreNoForeignKeys(): void
    {
        self::assertSame([], $this->introspector()->describeTable('order_items')->foreignKeys);
    }

    public function testAViewCarriesItsDefinitionAndNoIndex(): void
    {
        $view = $this->introspector()->describeTable('v_order_totals');

        self::assertSame(TableKind::View, $view->kind);
        self::assertNotNull($view->viewDefinition);
        self::assertStringContainsStringIgnoringCase('select', $view->viewDefinition);
        self::assertSame([], $view->indexes);
        self::assertNotNull($view->column('total_cents'));
    }

    public function testAnUnknownTableIsReported(): void
    {
        $this->expectException(TableNotFoundException::class);

        $this->introspector()->describeTable('does_not_exist');
    }

    public function testATableNameIsNeverTakenAsSql(): void
    {
        $this->expectException(TableNotFoundException::class);

        $this->introspector()->describeTable("orders' OR 1=1 --");
    }

    public function testThereIsNoSchemaLevelBelowADatabase(): void
    {
        $this->expectException(\App\Core\Driver\Exception\UnsupportedOperationException::class);

        $this->introspector()->listSchemas();
    }
}
