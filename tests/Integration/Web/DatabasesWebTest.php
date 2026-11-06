<?php

declare(strict_types=1);

namespace App\Tests\Integration\Web;

use App\Tests\Integration\Driver\RedisTarget;
use App\Tests\Integration\Driver\TargetDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

#[Group('integration')]
final class DatabasesWebTest extends WebApiTestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function targets(): iterable
    {
        yield 'MySQL' => ['TARGET_MYSQL_URL'];
        yield 'MariaDB' => ['TARGET_MARIADB_URL'];
        yield 'PostgreSQL' => ['TARGET_POSTGRES_URL'];
        yield 'ClickHouse' => ['TARGET_CLICKHOUSE_URL'];
    }

    private function save(string $envVar): string
    {
        $parameters = TargetDatabase::parameters($envVar);
        $body = ['name' => 'Target', 'engine' => TargetDatabase::engine($envVar)->value, 'host' => $parameters->host, 'port' => $parameters->port, 'database' => $parameters->database, 'username' => $parameters->username, 'password' => $parameters->password()];
        $id = self::arrayOf($this->call('POST', '/web/connections', $body)['connection'])['id'];
        self::assertIsString($id);

        return $id;
    }

    /**
     * @param array<array-key, mixed> $databases
     *
     * @return list<string>
     */
    private static function names(array $databases): array
    {
        $names = [];
        foreach ($databases as $row) {
            $name = self::arrayOf($row)['name'];
            self::assertIsString($name);
            $names[] = $name;
        }

        return $names;
    }

    #[DataProvider('targets')]
    public function testListsTheDatabasesWithoutTheSystemOnesByDefault(string $envVar): void
    {
        $this->seedAndLogin();
        $id = $this->save($envVar);

        $listed = $this->call('GET', '/web/connections/'.$id.'/databases');

        self::assertSame(200, $this->statusCode());
        $names = self::names(self::arrayOf($listed['databases']));
        self::assertContains('shop', $names);
        self::assertNotContains('information_schema', $names);
        self::assertNotContains('system', $names);
        self::assertNotContains('template0', $names);
        foreach (self::arrayOf($listed['databases']) as $row) {
            self::assertFalse(self::arrayOf($row)['system']);
        }
    }

    #[DataProvider('targets')]
    public function testTheSystemDatabasesCanBeAskedFor(string $envVar): void
    {
        if ('TARGET_POSTGRES_URL' === $envVar) {
            self::markTestSkipped('PostgreSQL has no system databases to unhide beyond the templates.');
        }
        $this->seedAndLogin();
        $id = $this->save($envVar);

        $listed = $this->call('GET', '/web/connections/'.$id.'/databases?system=1');

        $names = self::names(self::arrayOf($listed['databases']));
        self::assertContains('shop', $names);
        self::assertContains('TARGET_CLICKHOUSE_URL' === $envVar ? 'system' : 'information_schema', $names);
    }

    public function testTablesFollowTheDatabaseThatWasAskedFor(): void
    {
        $this->seedAndLogin();
        $id = $this->save('TARGET_MYSQL_URL');

        $own = self::names(self::arrayOf($this->call('GET', '/web/connections/'.$id.'/tables')['tables']));
        $other = self::names(self::arrayOf($this->call('GET', '/web/connections/'.$id.'/tables?database=information_schema')['tables']));

        self::assertContains('categories', $own);
        self::assertNotContains('categories', $other);
        self::assertNotSame([], $other);
    }

    public function testASingleTableIsReadInTheAskedDatabase(): void
    {
        $this->seedAndLogin();
        $id = $this->save('TARGET_MYSQL_URL');

        $this->call('GET', '/web/connections/'.$id.'/tables/categories?database=information_schema');

        self::assertSame(404, $this->statusCode());
        $this->call('GET', '/web/connections/'.$id.'/tables/categories');
        self::assertSame(200, $this->statusCode());
    }

    public function testTheConsoleRunsInTheAskedDatabase(): void
    {
        $this->seedAndLogin();
        $id = $this->save('TARGET_MYSQL_URL');

        $result = $this->call('POST', '/web/connections/'.$id.'/query', ['sql' => 'select database()', 'database' => 'information_schema']);

        self::assertSame(200, $this->statusCode());
        $cell = self::arrayOf(self::arrayOf($result['rows'])[0])[0];
        self::assertIsString($cell);
        self::assertSame('information_schema', strtolower($cell));
    }

    public function testAnUnknownDatabaseIsAGenericConnectionFailure(): void
    {
        $this->seedAndLogin();
        $id = $this->save('TARGET_POSTGRES_URL');

        $body = $this->call('GET', '/web/connections/'.$id.'/tables?database=does_not_exist_here');

        self::assertSame(502, $this->statusCode());
        self::assertSame('target_unreachable', $body['error']);
        self::assertStringNotContainsString('does_not_exist_here', json_encode($body, \JSON_THROW_ON_ERROR));
    }

    public function testAnInvalidDatabaseNameIsRefused(): void
    {
        $this->seedAndLogin();
        $id = $this->save('TARGET_MYSQL_URL');

        $this->call('GET', '/web/connections/'.$id.'/tables?database=%00');

        self::assertSame(422, $this->statusCode());
    }

    public function testRedisHasNoDatabaseList(): void
    {
        $seed = RedisTarget::seeded();
        try {
            $this->seedAndLogin();
            $id = $this->save(RedisTarget::ENV);

            $this->call('GET', '/web/connections/'.$id.'/databases');

            self::assertSame(501, $this->statusCode());
        } finally {
            $seed->flushdb();
        }
    }

    public function testAnotherAccountsConnectionHasNoDatabases(): void
    {
        $this->seedAndLogin('alice');
        $id = $this->save('TARGET_MYSQL_URL');
        $this->call('POST', '/web/logout');
        $this->seedUser('bob');
        $this->login('bob');

        $this->call('GET', '/web/connections/'.$id.'/databases');

        self::assertSame(404, $this->statusCode());
    }

    public function testListingRequiresASession(): void
    {
        $this->call('GET', '/web/connections/'.\Symfony\Component\Uid\Uuid::v7()->toRfc4122().'/databases');

        self::assertSame(401, $this->statusCode());
    }

    public function testPostgreSqlSchemasAreListedWithoutTheEngineOnes(): void
    {
        $this->seedAndLogin();
        $id = $this->save('TARGET_POSTGRES_URL');

        $listed = $this->call('GET', '/web/connections/'.$id.'/schemas');

        self::assertSame(200, $this->statusCode());
        $names = self::names(self::arrayOf($listed['schemas']));
        self::assertContains('public', $names);
        self::assertNotContains('information_schema', $names);
        self::assertNotContains('pg_catalog', $names);
        $all = self::names(self::arrayOf($this->call('GET', '/web/connections/'.$id.'/schemas?system=1')['schemas']));
        self::assertContains('information_schema', $all);
    }

    public function testOtherEnginesHaveNoSchemaLevel(): void
    {
        $this->seedAndLogin();
        $id = $this->save('TARGET_MYSQL_URL');

        $this->call('GET', '/web/connections/'.$id.'/schemas');

        self::assertSame(501, $this->statusCode());
    }

    public function testTablesAndTheConsoleFollowTheAskedSchema(): void
    {
        $this->seedAndLogin();
        $id = $this->save('TARGET_POSTGRES_URL');

        $own = self::names(self::arrayOf($this->call('GET', '/web/connections/'.$id.'/tables')['tables']));
        $other = self::names(self::arrayOf($this->call('GET', '/web/connections/'.$id.'/tables?schema=information_schema')['tables']));
        $this->call('GET', '/web/connections/'.$id.'/tables/columns?schema=information_schema');
        $described = $this->statusCode();
        $result = $this->call('POST', '/web/connections/'.$id.'/query', ['sql' => 'select current_schema()', 'schema' => 'information_schema']);

        self::assertContains('categories', $own);
        self::assertContains('columns', $other);
        self::assertSame(200, $described);
        self::assertSame('information_schema', self::arrayOf(self::arrayOf($result['rows'])[0])[0]);
    }

    public function testAnInvalidSchemaNameIsRefused(): void
    {
        $this->seedAndLogin();
        $id = $this->save('TARGET_POSTGRES_URL');

        $this->call('GET', '/web/connections/'.$id.'/tables?schema=%00');

        self::assertSame(422, $this->statusCode());
    }

    public function testTheDatabaseListSaysWhichOneIsTheDefault(): void
    {
        $this->seedAndLogin();
        $id = $this->save('TARGET_POSTGRES_URL');

        self::assertSame('shop', $this->call('GET', '/web/connections/'.$id.'/databases')['default']);
    }

    public function testAConnectionWithoutADatabaseCanBeSavedAndBrowsed(): void
    {
        $this->seedAndLogin();
        $parameters = TargetDatabase::parameters('TARGET_MYSQL_URL');
        $body = ['name' => 'Server', 'engine' => 'mysql', 'host' => $parameters->host, 'port' => $parameters->port, 'username' => $parameters->username, 'password' => $parameters->password()];
        $id = self::arrayOf($this->call('POST', '/web/connections', $body)['connection'])['id'];
        self::assertSame(201, $this->statusCode());
        self::assertIsString($id);

        $listed = $this->call('GET', '/web/connections/'.$id.'/databases');

        self::assertSame(200, $this->statusCode());
        self::assertNull($listed['default']);
        self::assertContains('shop', self::names(self::arrayOf($listed['databases'])));
        self::assertSame([], self::arrayOf($this->call('GET', '/web/connections/'.$id.'/tables')['tables']));
        self::assertContains('categories', self::names(self::arrayOf($this->call('GET', '/web/connections/'.$id.'/tables?database=shop')['tables'])));
    }
}
