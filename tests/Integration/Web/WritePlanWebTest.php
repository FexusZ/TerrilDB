<?php

declare(strict_types=1);

namespace App\Tests\Integration\Web;

use App\Core\Connection\Engine;
use App\Tests\Integration\Driver\TargetDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * Le plan d'une écriture sur les vraies cibles. Chaque test prouve que RIEN n'a été écrit : les lignes des tables
 * touchées sont comptées avant et après, y compris pour un DELETE sans WHERE qui viderait la table s'il était joué.
 */
#[Group('integration')]
final class WritePlanWebTest extends WebApiTestCase
{
    private string $connectionId = '';
    private string $envVar = '';

    /**
     * @return iterable<string, array{string}>
     */
    public static function explainable(): iterable
    {
        yield 'MySQL' => ['TARGET_MYSQL_URL'];
        yield 'MariaDB' => ['TARGET_MARIADB_URL'];
        yield 'MariaDB 13' => ['TARGET_MARIADB13_URL'];
        yield 'PostgreSQL' => ['TARGET_POSTGRES_URL'];
    }

    private function prepare(string $envVar): void
    {
        $this->envVar = $envVar;
        $this->seedAndLogin();
        $parameters = TargetDatabase::parameters($envVar);
        $created = $this->call('POST', '/web/connections', ['name' => 'Target', 'engine' => TargetDatabase::engine($envVar)->value, 'host' => $parameters->host, 'port' => $parameters->port, 'database' => $parameters->database, 'username' => $parameters->username, 'password' => $parameters->password()]);
        $id = self::arrayOf($created['connection'])['id'];
        self::assertIsString($id);
        $this->connectionId = $id;
    }

    /**
     * @return array<string, int>
     */
    private function counts(): array
    {
        $parameters = TargetDatabase::parameters($this->envVar);
        $driver = Engine::PostgreSql === TargetDatabase::engine($this->envVar) ? 'pgsql' : 'mysql';
        $pdo = new \PDO(\sprintf('%s:host=%s;port=%d;dbname=%s', $driver, $parameters->host, $parameters->port, $parameters->database), $parameters->username, $parameters->password(), [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);

        $counts = [];
        foreach (['order_items' => 'select count(*) from order_items', 'orders' => 'select count(*) from orders', 'categories' => 'select count(*) from categories', 'cancelled' => "select count(*) from orders where status = 'cancelled'"] as $name => $sql) {
            $statement = $pdo->query($sql);
            self::assertInstanceOf(\PDOStatement::class, $statement);
            $counts[$name] = (int) $statement->fetchColumn();
        }

        return $counts;
    }

    /**
     * @return array<string, mixed>
     */
    private function explain(string $sql): array
    {
        return $this->call('POST', '/web/connections/'.$this->connectionId.'/plan', ['sql' => $sql, 'write' => true]);
    }

    #[DataProvider('explainable')]
    public function testADeleteByFullScanIsExplainedAndNotRun(string $envVar): void
    {
        $this->prepare($envVar);
        $before = $this->counts();

        $body = $this->explain('delete from order_items where quantity > 0');

        self::assertSame(200, $this->statusCode());
        $write = self::arrayOf($body['write']);
        self::assertSame('delete', $write['operation']);
        self::assertSame('order_items', $write['relation']);
        self::assertTrue($write['fullScan']);
        self::assertIsInt($write['rows']);
        // MySQL et MariaDB annoncent parfois très peu de lignes tant que la table n'est pas analysée (base neuve en CI) :
        // le verdict suit l'estimation du moteur, pas le contenu réel.
        self::assertSame(Engine::PostgreSql !== TargetDatabase::engine($envVar) && $write['rows'] >= 1000, $write['locksWide']);
        self::assertSame($before, $this->counts(), 'rien n\'a été supprimé');
    }

    /**
     * MariaDB 11.8 répond « Deleting all rows » sans nom de table : le plan n'a alors pas de résumé, mais surtout la
     * table n'est pas vidée.
     */
    #[DataProvider('explainable')]
    public function testADeleteWithoutWhereDoesNotEmptyTheTable(string $envVar): void
    {
        $this->prepare($envVar);
        $before = $this->counts();

        $this->explain('delete from order_items');
        self::assertSame(200, $this->statusCode());

        self::assertSame($before, $this->counts());
        self::assertSame(7000, $before['order_items']);
    }

    #[DataProvider('explainable')]
    public function testAnUpdateByKeyIsExplainedAndNotRun(string $envVar): void
    {
        $this->prepare($envVar);
        $before = $this->counts();

        $body = $this->explain("update orders set status = 'cancelled' where id = 5");

        self::assertSame(200, $this->statusCode());
        $write = self::arrayOf($body['write']);
        self::assertSame('update', $write['operation']);
        self::assertSame('orders', $write['relation']);
        self::assertFalse($write['fullScan']);
        self::assertIsString($write['index']);
        self::assertFalse($write['locksWide']);
        self::assertSame($before, $this->counts(), 'rien n\'a été modifié');
        self::assertSame(750, $before['cancelled']);
    }

    #[DataProvider('explainable')]
    public function testAnInsertSelectIsExplainedAndNotRun(string $envVar): void
    {
        $this->prepare($envVar);
        $before = $this->counts();

        $body = $this->explain('insert into categories (name) select name from products');

        self::assertSame(200, $this->statusCode());
        if (Engine::MariaDb === TargetDatabase::engine($envVar)) {
            // MariaDB n'écrit pas l'insertion dans son plan : seul le SELECT y figure.
            self::assertArrayHasKey('write', $body);
            self::assertNull($body['write']);
        } else {
            self::assertSame('insert', self::arrayOf($body['write'])['operation']);
            self::assertSame('categories', self::arrayOf($body['write'])['relation']);
        }
        self::assertSame($before, $this->counts(), 'rien n\'a été inséré');
    }

    public function testMySql26CannotExplainADeleteButExplainsAnInsert(): void
    {
        $this->prepare('TARGET_MYSQL26_URL');
        $before = $this->counts();

        $body = $this->explain('delete from order_items');
        self::assertSame(501, $this->statusCode());
        self::assertSame('plan_unavailable', $body['error']);

        $body = $this->explain('insert into categories (name) select name from products');
        self::assertSame(200, $this->statusCode());
        self::assertSame('insert', self::arrayOf($body['write'])['operation']);
        self::assertSame($before, $this->counts());
    }

    public function testRefusesWhatIsNotAPlainWrite(): void
    {
        $this->prepare('TARGET_POSTGRES_URL');
        $before = $this->counts();

        foreach (['select 1', 'explain analyze delete from order_items', 'drop table order_items', 'delete from order_items; delete from orders', 'with d as (select 1) delete from order_items'] as $sql) {
            $body = $this->explain($sql);
            self::assertSame(403, $this->statusCode(), $sql);
            self::assertSame('query_refused', $body['error'], $sql);
        }
        self::assertSame($before, $this->counts());
    }

    public function testWithoutTheWriteFlagAWriteIsStillRefused(): void
    {
        $this->prepare('TARGET_POSTGRES_URL');

        $body = $this->call('POST', '/web/connections/'.$this->connectionId.'/plan', ['sql' => 'delete from order_items']);

        self::assertSame(403, $this->statusCode());
        self::assertSame('query_refused', $body['error']);
    }

    public function testClickHouseHasNoWritePlan(): void
    {
        $this->prepare('TARGET_CLICKHOUSE_URL');

        $body = $this->explain('delete from order_items where id = 1');

        self::assertSame(501, $this->statusCode());
        self::assertSame('plan_unavailable', $body['error']);
    }

    public function testAReadPlanHasNoWriteSummary(): void
    {
        $this->prepare('TARGET_POSTGRES_URL');

        $body = $this->call('POST', '/web/connections/'.$this->connectionId.'/plan', ['sql' => 'select * from orders where id = 5']);

        self::assertSame(200, $this->statusCode());
        self::assertArrayNotHasKey('write', $body);
    }

    public function testAWritePlanIsAuditedAsAPlanWithTheUsersOwnStatement(): void
    {
        $this->prepare('TARGET_POSTGRES_URL');
        $users = self::getContainer()->get(\App\Repository\UserRepository::class);
        self::assertInstanceOf(\App\Repository\UserRepository::class, $users);
        $user = $users->findByLogin('alice');
        self::assertNotNull($user);

        $this->explain('delete from order_items where quantity = 3');

        $event = $this->auditEvents($user, 'query')[0];
        self::assertSame('plan', $event->getDetails()['purpose']);
        self::assertSame('delete from order_items where quantity = 3', $event->getDetails()['sql']);
    }
}
