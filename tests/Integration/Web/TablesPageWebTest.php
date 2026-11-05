<?php

declare(strict_types=1);

namespace App\Tests\Integration\Web;

use App\Tests\Integration\Driver\ManyTablesFixture;
use App\Tests\Integration\Driver\TargetDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

#[Group('integration')]
final class TablesPageWebTest extends WebApiTestCase
{
    private const TARGETS = ['TARGET_MYSQL_URL', 'TARGET_POSTGRES_URL', 'TARGET_CLICKHOUSE_URL'];

    /** @var array<string, bool> */
    private static array $ready = [];

    public static function setUpBeforeClass(): void
    {
        foreach (self::TARGETS as $envVar) {
            try {
                ManyTablesFixture::create($envVar);
                self::$ready[$envVar] = true;
            } catch (\Throwable) {
                self::$ready[$envVar] = false;
            }
        }
    }

    public static function tearDownAfterClass(): void
    {
        foreach (self::TARGETS as $envVar) {
            if (self::$ready[$envVar] ?? false) {
                ManyTablesFixture::drop($envVar);
            }
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function targets(): iterable
    {
        yield 'MySQL' => ['TARGET_MYSQL_URL'];
        yield 'PostgreSQL' => ['TARGET_POSTGRES_URL'];
        yield 'ClickHouse' => ['TARGET_CLICKHOUSE_URL'];
    }

    private function save(string $envVar): string
    {
        if (!(self::$ready[$envVar] ?? false)) {
            self::markTestSkipped(\sprintf('%s is unreachable (docker compose --profile targets up -d --wait).', $envVar));
        }
        $this->seedAndLogin();
        $parameters = TargetDatabase::parameters($envVar);
        $body = ['name' => 'Target', 'engine' => TargetDatabase::engine($envVar)->value, 'host' => $parameters->host, 'port' => $parameters->port, 'database' => $parameters->database, 'username' => $parameters->username, 'password' => $parameters->password()];
        $id = self::arrayOf($this->call('POST', '/web/connections', $body)['connection'])['id'];
        self::assertIsString($id);

        return $id;
    }

    /**
     * @param array<string, mixed> $page
     *
     * @return list<string>
     */
    private static function namesOf(array $page): array
    {
        $names = [];
        foreach (self::arrayOf($page['tables']) as $row) {
            $name = self::arrayOf($row)['name'];
            self::assertIsString($name);
            $names[] = $name;
        }

        return $names;
    }

    #[DataProvider('targets')]
    public function testWalksTheTablesAHundredAtATimeThroughTheCursor(string $envVar): void
    {
        $id = $this->save($envVar);
        $base = '/web/connections/'.$id.'/tables?database='.ManyTablesFixture::DATABASE.'&limit=100';

        $first = $this->call('GET', $base);
        self::assertSame(200, $this->statusCode());
        self::assertCount(100, self::arrayOf($first['tables']));
        self::assertSame(\count(ManyTablesFixture::names()), $first['total']);
        self::assertIsString($first['nextCursor']);

        $names = self::namesOf($first);
        $cursor = $first['nextCursor'];
        $pages = 1;
        while (null !== $cursor) {
            self::assertIsString($cursor);
            $page = $this->call('GET', $base.'&cursor='.rawurlencode($cursor));
            self::assertSame(200, $this->statusCode());
            self::assertNull($page['total']);
            $names = [...$names, ...self::namesOf($page)];
            $cursor = $page['nextCursor'];
            self::assertLessThan(20, ++$pages);
        }

        self::assertSame(ManyTablesFixture::names(), $names);
        self::assertSame(3, $pages);
    }

    #[DataProvider('targets')]
    public function testSearchFiltersOnTheServer(string $envVar): void
    {
        $id = $this->save($envVar);

        $page = $this->call('GET', '/web/connections/'.$id.'/tables?database='.ManyTablesFixture::DATABASE.'&limit=500&q=zz_a');

        self::assertSame(['zz_a%b', 'zz_aXb', 'zz_a_b'], self::namesOf($page));
        self::assertSame(3, $page['total']);
        self::assertNull($page['nextCursor']);
    }

    #[DataProvider('targets')]
    public function testWithoutPaginationParametersTheWholeListStaysAvailable(string $envVar): void
    {
        $id = $this->save($envVar);

        $all = $this->call('GET', '/web/connections/'.$id.'/tables?database='.ManyTablesFixture::DATABASE);

        self::assertSame(ManyTablesFixture::names(), self::namesOf($all));
        self::assertArrayNotHasKey('nextCursor', $all);
        self::assertArrayNotHasKey('total', $all);
    }

    public function testTheLimitIsBounded(): void
    {
        $id = $this->save('TARGET_MYSQL_URL');
        $base = '/web/connections/'.$id.'/tables?database='.ManyTablesFixture::DATABASE;

        foreach (['0', '501', '-3', 'abc', '1.5'] as $limit) {
            $body = $this->call('GET', $base.'&limit='.$limit);
            self::assertSame(400, $this->statusCode(), 'limit '.$limit);
            self::assertSame('bad_request', $body['error']);
        }
        $this->call('GET', $base.'&limit=500');
        self::assertSame(200, $this->statusCode());
    }

    public function testAnEmptyCursorMeansTheFirstPage(): void
    {
        $id = $this->save('TARGET_MYSQL_URL');

        $page = $this->call('GET', '/web/connections/'.$id.'/tables?database='.ManyTablesFixture::DATABASE.'&limit=2&cursor=');

        self::assertSame(['B_up', 'a_low'], self::namesOf($page));
    }

    public function testRedisCannotBePaginated(): void
    {
        $seed = \App\Tests\Integration\Driver\RedisTarget::seeded();
        try {
            $this->seedAndLogin();
            $parameters = TargetDatabase::parameters(\App\Tests\Integration\Driver\RedisTarget::ENV);
            $created = $this->call('POST', '/web/connections', ['name' => 'R', 'engine' => 'redis', 'host' => $parameters->host, 'port' => $parameters->port, 'database' => $parameters->database, 'username' => $parameters->username, 'password' => $parameters->password()]);
            $id = self::arrayOf($created['connection'])['id'];
            self::assertIsString($id);

            $this->call('GET', '/web/connections/'.$id.'/tables?limit=10');

            self::assertSame(501, $this->statusCode());
        } finally {
            $seed->flushdb();
        }
    }
}
