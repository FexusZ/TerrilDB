<?php

declare(strict_types=1);

namespace App\Tests\Integration\Web;

use App\Tests\Integration\Driver\TargetDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

#[Group('integration')]
final class SchemaDiagramWebTest extends WebApiTestCase
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
        $created = $this->call('POST', '/web/connections', ['name' => 'Target', 'engine' => TargetDatabase::engine($envVar)->value, 'host' => $parameters->host, 'port' => $parameters->port, 'database' => $parameters->database, 'username' => $parameters->username, 'password' => $parameters->password()]);
        $id = self::arrayOf($created['connection'])['id'];
        self::assertIsString($id);

        return $id;
    }

    public function testRequiresASession(): void
    {
        $this->call('GET', '/web/connections/00000000-0000-7000-8000-000000000000/diagram');

        self::assertSame(401, $this->statusCode());
    }

    #[DataProvider('targets')]
    public function testDrawsTheTablesAndTheirRelations(string $envVar): void
    {
        $this->seedAndLogin();
        $id = $this->saveTarget($envVar);

        $body = $this->call('GET', '/web/connections/'.$id.'/diagram');

        self::assertSame(200, $this->statusCode());
        self::assertFalse($body['truncated']);
        $tables = array_map(self::arrayOf(...), self::arrayOf($body['tables']));
        $names = array_map(static fn (array $t): mixed => $t['name'], $tables);
        self::assertSame(['categories', 'customers', 'order_items', 'orders', 'products'], $names, 'views are not part of the diagram');

        $items = $tables[2];
        self::assertSame(['name', 'columns'], array_keys($items));
        $columns = array_map(self::arrayOf(...), self::arrayOf($items['columns']));
        self::assertSame(['name', 'type', 'nullable', 'primary'], array_keys($columns[0]));
        $primary = array_values(array_filter($columns, static fn (array $c): bool => true === $c['primary']));
        self::assertNotEmpty($primary);

        $relations = array_map(self::arrayOf(...), self::arrayOf($body['relations']));
        $summary = array_map(static fn (array $r): string => \sprintf('%s>%s', \is_string($r['table']) ? $r['table'] : '', \is_string($r['referencedTable']) ? $r['referencedTable'] : ''), $relations);
        self::assertContains('order_items>orders', $summary);
        self::assertContains('orders>customers', $summary);
        self::assertContains('categories>categories', $summary, 'a self reference stays a relation');
        self::assertSame(['name', 'table', 'columns', 'referencedTable', 'referencedColumns'], array_keys($relations[0]));
    }

    public function testAnotherAccountsConnectionIsNotFound(): void
    {
        $this->seedAndLogin('alice');
        $id = $this->saveTarget('TARGET_POSTGRES_URL');
        $this->call('POST', '/web/logout');
        $this->seedUser('bob');
        $this->login('bob');

        $this->call('GET', '/web/connections/'.$id.'/diagram');

        self::assertSame(404, $this->statusCode());
    }
}
