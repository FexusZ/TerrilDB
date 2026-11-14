<?php

declare(strict_types=1);

namespace App\Tests\Integration\Web;

use App\Tests\Integration\Driver\TargetDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

#[Group('integration')]
final class SchemaQueryWebTest extends WebApiTestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function targets(): iterable
    {
        yield from TargetDatabase::all();
    }

    private function saveTarget(string $envVar): string
    {
        $parameters = TargetDatabase::parameters($envVar);
        $created = $this->call('POST', '/web/connections', [
            'name' => 'Target',
            'engine' => TargetDatabase::engine($envVar)->value,
            'host' => $parameters->host,
            'port' => $parameters->port,
            'database' => $parameters->database,
            'username' => $parameters->username,
            'password' => $parameters->password(),
        ]);
        self::assertSame(201, $this->statusCode());
        self::assertIsArray($created['connection']);
        self::assertIsString($created['connection']['id']);

        return $created['connection']['id'];
    }

    /**
     * @param array<string, mixed> $extra
     *
     * @return array<string, mixed>
     */
    private function query(string $id, string $sql, array $extra = []): array
    {
        return $this->call('POST', '/web/connections/'.$id.'/query', ['sql' => $sql] + $extra);
    }

    #[DataProvider('targets')]
    public function testListsTheTablesAndViews(string $envVar): void
    {
        $this->seedAndLogin();
        $id = $this->saveTarget($envVar);

        $body = $this->call('GET', '/web/connections/'.$id.'/tables');

        self::assertSame(200, $this->statusCode());
        $tables = self::arrayOf($body['tables']);
        self::assertCount(7, $tables);
        $first = self::arrayOf($tables[0]);
        self::assertSame(['name' => 'categories', 'kind' => 'table'], array_intersect_key($first, ['name' => 1, 'kind' => 1]));
        self::assertSame('view', self::arrayOf($tables[5])['kind']);
        self::assertArrayHasKey('estimatedRows', $first);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function relationalTargets(): iterable
    {
        yield from TargetDatabase::relational();
    }

    #[DataProvider('relationalTargets')]
    public function testDescribesATable(string $envVar): void
    {
        $this->seedAndLogin();
        $id = $this->saveTarget($envVar);

        $body = $this->call('GET', '/web/connections/'.$id.'/tables/order_items');

        self::assertSame(200, $this->statusCode());
        $table = self::arrayOf($body['table']);
        self::assertSame('order_items', $table['name']);
        self::assertSame('table', $table['kind']);
        $columns = self::arrayOf($table['columns']);
        self::assertSame(['id', 'order_id', 'line_no', 'product_id', 'quantity', 'unit_price_cents'], array_column($columns, 'name'));
        self::assertSame(['name', 'type', 'nullable', 'default', 'autoIncrement', 'comment'], array_keys(self::arrayOf($columns[0])));
        $indexes = self::arrayOf($table['indexes']);
        self::assertSame(['name', 'columns', 'unique', 'primary', 'sizeBytes'], array_keys(self::arrayOf($indexes[0])));
        self::assertGreaterThan(0, $table['dataBytes']);
        self::assertGreaterThan(0, $table['indexBytes']);
        $foreignKeys = self::arrayOf($table['foreignKeys']);
        self::assertCount(2, $foreignKeys);
        self::assertSame(['name', 'columns', 'referencedTable', 'referencedColumns', 'onUpdate', 'onDelete'], array_keys(self::arrayOf($foreignKeys[0])));
        self::assertNull($table['viewDefinition']);
    }

    public function testDescribesAClickHouseTableWithItsSortingKeyAndNoForeignKey(): void
    {
        $this->seedAndLogin();
        $id = $this->saveTarget('TARGET_CLICKHOUSE_URL');

        $body = $this->call('GET', '/web/connections/'.$id.'/tables/order_items');

        self::assertSame(200, $this->statusCode());
        $table = self::arrayOf($body['table']);
        self::assertSame(['id', 'order_id', 'line_no', 'product_id', 'quantity', 'unit_price_cents'], array_column(self::arrayOf($table['columns']), 'name'));
        $indexes = array_map(self::arrayOf(...), self::arrayOf($table['indexes']));
        self::assertSame(['ORDER BY', 'idx_order_items_product'], array_column($indexes, 'name'));
        self::assertSame([false, false], array_column($indexes, 'primary'));
        self::assertSame([], $table['foreignKeys']);
    }

    #[DataProvider('targets')]
    public function testADescribedViewCarriesItsDefinition(string $envVar): void
    {
        $this->seedAndLogin();
        $id = $this->saveTarget($envVar);

        $body = $this->call('GET', '/web/connections/'.$id.'/tables/v_order_totals');

        self::assertIsArray($body['table']);
        self::assertSame('view', $body['table']['kind']);
        self::assertIsString($body['table']['viewDefinition']);
        self::assertStringContainsString('order_items', $body['table']['viewDefinition']);
    }

    public function testAnUnknownTableIsNotFound(): void
    {
        $this->seedAndLogin();
        $id = $this->saveTarget('TARGET_POSTGRES_URL');

        $this->call('GET', '/web/connections/'.$id.'/tables/no_such_table');

        self::assertSame(404, $this->statusCode());
    }

    #[DataProvider('targets')]
    public function testRunsAReadOnlyQuery(string $envVar): void
    {
        $this->seedAndLogin();
        $id = $this->saveTarget($envVar);

        $body = $this->query($id, 'select id, name from categories order by id', ['maxRows' => 3]);

        self::assertSame(200, $this->statusCode());
        self::assertSame(['id', 'name'], $body['columns']);
        self::assertIsArray($body['rows']);
        self::assertCount(3, $body['rows']);
        self::assertSame(3, $body['rowCount']);
        self::assertTrue($body['truncated']);
        self::assertNull($body['affectedRows']);
        self::assertIsInt($body['durationMs']);
    }

    #[DataProvider('targets')]
    public function testWritesAreRefusedByDefault(string $envVar): void
    {
        $this->seedAndLogin();
        $id = $this->saveTarget($envVar);

        $body = $this->query($id, "insert into categories (name) values ('sneaky')");

        self::assertSame(403, $this->statusCode());
        self::assertSame('query_refused', $body['error']);
        self::assertSame('not_read_only', $body['reason']);
        $count = $this->query($id, "select count(*) from categories where name = 'sneaky'");
        self::assertEquals(0, self::arrayOf(self::arrayOf($count['rows'])[0])[0]);
    }

    #[DataProvider('targets')]
    public function testADestructiveStatementAsksForConfirmation(string $envVar): void
    {
        $this->seedAndLogin();
        $id = $this->saveTarget($envVar);

        $body = $this->query($id, 'delete from categories', ['mode' => 'read_write']);

        self::assertSame(409, $this->statusCode());
        self::assertSame('confirmation_required', $body['error']);
        self::assertSame('destructive_without_where', $body['reason']);
    }

    public function testAHiddenSecondStatementIsRefused(): void
    {
        $this->seedAndLogin();
        $id = $this->saveTarget('TARGET_MYSQL_URL');

        $body = $this->query($id, 'select 1 /*! ; drop table categories */');

        self::assertSame(403, $this->statusCode());
        self::assertSame('multiple_statements', $body['reason']);
    }

    #[DataProvider('targets')]
    public function testAnEngineErrorComesBackWithItsMessage(string $envVar): void
    {
        $this->seedAndLogin();
        $id = $this->saveTarget($envVar);

        $body = $this->query($id, 'select * from no_such_table');

        self::assertSame(400, $this->statusCode());
        self::assertSame('query_failed', $body['error']);
        self::assertIsString($body['message']);
        self::assertStringContainsString('no_such_table', $body['message']);
    }

    public function testLimitsAndModesAreValidated(): void
    {
        $this->seedAndLogin();
        $id = $this->saveTarget('TARGET_POSTGRES_URL');

        $this->query($id, 'select 1', ['maxRows' => 0]);
        self::assertSame(422, $this->statusCode());

        $this->query($id, 'select 1', ['timeoutMs' => 5]);
        self::assertSame(422, $this->statusCode());

        $this->query($id, 'select 1', ['mode' => 'root']);
        self::assertSame(422, $this->statusCode());

        $this->call('POST', '/web/connections/'.$id.'/query', ['sql' => 5]);
        self::assertSame(400, $this->statusCode());
    }

    public function testADurationAboveWhatTheProxyAllowsIsRefusedNotCutOff(): void
    {
        $this->seedAndLogin();
        $id = $this->saveTarget('TARGET_POSTGRES_URL');

        $body = $this->query($id, 'select 1', ['timeoutMs' => 100_001]);
        self::assertSame(422, $this->statusCode());
        self::assertSame('invalid_input', $body['error']);

        $this->query($id, 'select 1', ['timeoutMs' => 100_000]);
        self::assertSame(200, $this->statusCode());
    }

    public function testASlowQueryIsStopped(): void
    {
        $this->seedAndLogin();
        $id = $this->saveTarget('TARGET_POSTGRES_URL');

        $body = $this->query($id, 'select pg_sleep(5)', ['timeoutMs' => 300]);

        self::assertSame(408, $this->statusCode());
        self::assertSame('query_timeout', $body['error']);
    }

    public function testBigIntegersTravelAsStrings(): void
    {
        $this->seedAndLogin();
        $id = $this->saveTarget('TARGET_POSTGRES_URL');

        $body = $this->query($id, 'select 9223372036854775807::bigint as big, 1 as small');

        self::assertSame([['9223372036854775807', 1]], $body['rows']);
    }

    public function testQueriesNeedTheSessionKey(): void
    {
        $this->seedAndLogin();
        $id = $this->saveTarget('TARGET_POSTGRES_URL');
        $this->client->getCookieJar()->expire('TERRILDB_UNLOCK');

        $body = $this->query($id, 'select 1');

        self::assertSame(401, $this->statusCode());
        self::assertSame('locked', $body['error']);
    }
}
