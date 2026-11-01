<?php

declare(strict_types=1);

namespace App\Tests\Integration\Web;

use App\Tests\Integration\Driver\TargetDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

#[Group('integration')]
final class PlanWebTest extends WebApiTestCase
{
    private const JOIN = "select o.id, c.email from orders o join customers c on c.id = o.customer_id where o.status = 'shipped' order by o.id limit 5";

    private string $connectionId = '';

    /**
     * @return iterable<string, array{string}>
     */
    public static function targets(): iterable
    {
        yield from TargetDatabase::relational();
    }

    private function prepare(string $envVar): void
    {
        $this->seedAndLogin();
        $parameters = TargetDatabase::parameters($envVar);
        $created = $this->call('POST', '/web/connections', ['name' => 'Target', 'engine' => TargetDatabase::engine($envVar)->value, 'host' => $parameters->host, 'port' => $parameters->port, 'database' => $parameters->database, 'username' => $parameters->username, 'password' => $parameters->password()]);
        $id = self::arrayOf($created['connection'])['id'];
        self::assertIsString($id);
        $this->connectionId = $id;
    }

    /**
     * @return list<array<array-key, mixed>>
     */
    private static function flatten(mixed $node): array
    {
        $node = self::arrayOf($node);
        $all = [$node];
        foreach (self::arrayOf($node['children']) as $child) {
            array_push($all, ...self::flatten($child));
        }

        return $all;
    }

    public function testClickHouseHasNoPlanToShow(): void
    {
        $this->prepare('TARGET_CLICKHOUSE_URL');

        $body = $this->call('POST', '/web/connections/'.$this->connectionId.'/plan', ['sql' => 'select * from orders']);

        self::assertSame(501, $this->statusCode());
        self::assertSame('plan_unavailable', $body['error']);
    }

    #[DataProvider('targets')]
    public function testExplainsAJoin(string $envVar): void
    {
        $this->prepare($envVar);

        $body = $this->call('POST', '/web/connections/'.$this->connectionId.'/plan', ['sql' => self::JOIN]);

        self::assertSame(200, $this->statusCode());
        $plan = self::arrayOf($body['plan']);
        self::assertIsNumeric($plan['totalCost']);
        $nodes = self::flatten($plan['root']);
        self::assertGreaterThanOrEqual(3, \count($nodes));
        $relations = array_values(array_filter(array_map(static fn (array $n): mixed => $n['relation'], $nodes)));
        self::assertGreaterThanOrEqual(2, \count($relations), 'les deux tables de la jointure apparaissent');
        self::assertSame(['kind', 'label', 'relation', 'index', 'rows', 'cost', 'condition', 'fullScan', 'children'], array_keys($nodes[0]));
        self::assertIsArray($body['findings']);
    }

    #[DataProvider('targets')]
    public function testShowsAFullScan(string $envVar): void
    {
        $this->prepare($envVar);

        $body = $this->call('POST', '/web/connections/'.$this->connectionId.'/plan', ['sql' => 'select * from order_items where quantity > 3']);

        $nodes = self::flatten(self::arrayOf($body['plan'])['root']);
        $scans = array_values(array_filter($nodes, static fn (array $n): bool => 'full_scan' === $n['kind']));
        self::assertCount(1, $scans);
        self::assertSame('order_items', $scans[0]['relation']);
        self::assertTrue($scans[0]['fullScan']);
    }

    public function testTheStatementIsNotRun(): void
    {
        $this->prepare('TARGET_POSTGRES_URL');

        // pg_sleep(5) coûterait cinq secondes s'il était exécuté ; EXPLAIN simple ne l'exécute pas.
        $started = microtime(true);
        $this->call('POST', '/web/connections/'.$this->connectionId.'/plan', ['sql' => 'select pg_sleep(5)']);

        self::assertSame(200, $this->statusCode());
        self::assertLessThan(3.0, microtime(true) - $started);
    }

    public function testRefusesAnythingButAPlainRead(): void
    {
        $this->prepare('TARGET_POSTGRES_URL');

        foreach (['delete from categories', 'select 1; select 2', 'show server_version', ''] as $sql) {
            $body = $this->call('POST', '/web/connections/'.$this->connectionId.'/plan', ['sql' => $sql]);
            self::assertSame(403, $this->statusCode(), $sql);
            self::assertSame('query_refused', $body['error']);
        }
    }

    public function testExplainingASyntaxErrorReportsTheEngineMessage(): void
    {
        $this->prepare('TARGET_POSTGRES_URL');

        $body = $this->call('POST', '/web/connections/'.$this->connectionId.'/plan', ['sql' => 'select * from no_such_table']);

        self::assertSame(400, $this->statusCode());
        self::assertSame('query_failed', $body['error']);
    }

    public function testAPlanIsAuditedWithTheUsersOwnStatement(): void
    {
        $this->prepare('TARGET_POSTGRES_URL');
        $user = $this->auditUser();

        $this->call('POST', '/web/connections/'.$this->connectionId.'/plan', ['sql' => 'select 1;']);

        $event = $this->auditEvents($user, 'query')[0];
        self::assertSame('plan', $event->getDetails()['purpose']);
        self::assertSame('select 1;', $event->getDetails()['sql']);
    }

    public function testAnotherAccountsConnectionIsNotFound(): void
    {
        $this->prepare('TARGET_POSTGRES_URL');
        $id = $this->connectionId;
        $this->call('POST', '/web/logout');
        $this->seedUser('bob');
        $this->login('bob');

        $this->call('POST', '/web/connections/'.$id.'/plan', ['sql' => 'select 1']);

        self::assertSame(404, $this->statusCode());
    }

    public function testNeedsTheSessionKey(): void
    {
        $this->prepare('TARGET_POSTGRES_URL');
        $this->client->getCookieJar()->expire('TERRILDB_UNLOCK');

        $this->call('POST', '/web/connections/'.$this->connectionId.'/plan', ['sql' => 'select 1']);

        self::assertSame(401, $this->statusCode());
    }

    private function auditUser(): \App\Entity\User
    {
        $users = self::getContainer()->get(\App\Repository\UserRepository::class);
        self::assertInstanceOf(\App\Repository\UserRepository::class, $users);
        $user = $users->findByLogin('alice');
        self::assertNotNull($user);

        return $user;
    }
}
