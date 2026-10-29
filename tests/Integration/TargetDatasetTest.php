<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception\ConnectionException;
use Doctrine\DBAL\Tools\DsnParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Vérifie le jeu de données des bases cibles de test (docker compose --profile targets).
 */
#[Group('integration')]
final class TargetDatasetTest extends TestCase
{
    private const TABLES = ['categories', 'customers', 'orders', 'order_items', 'products'];
    private const VIEWS = ['v_order_totals', 'v_product_sales'];
    private const MIN_TOTAL_ROWS = 10_000;

    /** @return iterable<string, array{string}> */
    public static function engines(): iterable
    {
        yield 'MySQL' => ['TARGET_MYSQL_URL'];
        yield 'MariaDB' => ['TARGET_MARIADB_URL'];
        yield 'PostgreSQL' => ['TARGET_POSTGRES_URL'];
    }

    #[DataProvider('engines')]
    public function testExposesTheExpectedTables(string $envVar): void
    {
        $tables = $this->connect($envVar)->createSchemaManager()->listTableNames();
        sort($tables);

        self::assertSame(self::TABLES, $tables);
    }

    #[DataProvider('engines')]
    public function testExposesTheExpectedViews(string $envVar): void
    {
        $connection = $this->connect($envVar);
        $views = $connection->fetchFirstColumn(
            'SELECT table_name FROM information_schema.views WHERE table_schema = ? ORDER BY table_name',
            [$this->currentSchema($connection)],
        );

        self::assertSame(self::VIEWS, $views);
    }

    #[DataProvider('engines')]
    public function testDeclaresTheForeignKeys(string $envVar): void
    {
        $manager = $this->connect($envVar)->createSchemaManager();
        $actual = [];
        foreach (self::TABLES as $table) {
            foreach ($manager->introspectTable($table)->getForeignKeys() as $foreignKey) {
                $actual[] = sprintf(
                    '%s(%s)->%s(%s)',
                    $table,
                    implode(',', $foreignKey->getReferencingColumnNames()->toStrings()),
                    $foreignKey->getReferencedTableName()->getUnqualifiedName()->getValue(),
                    implode(',', $foreignKey->getReferencedColumnNames()->toStrings()),
                );
            }
        }
        sort($actual);

        self::assertSame([
            'categories(parent_id)->categories(id)',
            'order_items(order_id)->orders(id)',
            'order_items(product_id)->products(id)',
            'orders(customer_id)->customers(id)',
            'products(category_id)->categories(id)',
        ], $actual);
    }

    /**
     * @param list<string> $columns
     */
    #[DataProvider('indexExpectations')]
    public function testDeclaresTheIndexes(string $table, array $columns, bool $unique): void
    {
        foreach (self::engines() as [$envVar]) {
            $indexes = $this->connect($envVar)->createSchemaManager()->introspectTable($table)->getIndexes();
            $found = array_filter(
                $indexes,
                static fn ($index): bool => $index->spansColumns($columns) && (!$unique || $index->isUnique()),
            );

            self::assertNotEmpty($found, sprintf(
                '%s: index %s(%s)%s manquant',
                $envVar,
                $table,
                implode(',', $columns),
                $unique ? ' unique' : '',
            ));
        }
    }

    /** @return iterable<string, array{string, list<string>, bool}> */
    public static function indexExpectations(): iterable
    {
        yield 'customers.email unique' => ['customers', ['email'], true];
        yield 'products.sku unique' => ['products', ['sku'], true];
        yield 'products.category_id' => ['products', ['category_id'], false];
        yield 'orders (customer_id, ordered_at)' => ['orders', ['customer_id', 'ordered_at'], false];
        yield 'orders.status' => ['orders', ['status'], false];
        yield 'order_items (order_id, line_no) unique' => ['order_items', ['order_id', 'line_no'], true];
        yield 'order_items.product_id' => ['order_items', ['product_id'], false];
    }

    #[DataProvider('engines')]
    public function testHoldsAtLeastTenThousandRows(string $envVar): void
    {
        $connection = $this->connect($envVar);
        $total = 0;
        foreach (self::TABLES as $table) {
            $rows = (int) $connection->fetchOne(sprintf('SELECT COUNT(*) FROM %s', $table));
            self::assertGreaterThan(0, $rows, sprintf('La table %s est vide', $table));
            $total += $rows;
        }

        self::assertGreaterThanOrEqual(self::MIN_TOTAL_ROWS, $total);
    }

    #[DataProvider('engines')]
    public function testViewsReturnRows(string $envVar): void
    {
        $connection = $this->connect($envVar);

        foreach (self::VIEWS as $view) {
            self::assertGreaterThan(0, (int) $connection->fetchOne(sprintf('SELECT COUNT(*) FROM %s', $view)));
        }
    }

    private function currentSchema(Connection $connection): string
    {
        return (string) $connection->fetchOne(
            $connection->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\PostgreSQLPlatform
                ? 'SELECT current_schema()'
                : 'SELECT DATABASE()',
        );
    }

    private function connect(string $envVar): Connection
    {
        $url = getenv($envVar);
        if (false === $url || '' === $url) {
            self::markTestSkipped(sprintf('%s n\'est pas défini.', $envVar));
        }

        $params = (new DsnParser(['pdo-mysql' => 'pdo_mysql', 'pdo-pgsql' => 'pdo_pgsql']))->parse($url);
        $connection = DriverManager::getConnection($params);

        try {
            $connection->executeQuery('SELECT 1');
        } catch (ConnectionException $e) {
            self::markTestSkipped(sprintf('Base injoignable (%s) : %s', $envVar, $e->getMessage()));
        }

        return $connection;
    }
}
