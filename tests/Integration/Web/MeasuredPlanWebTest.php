<?php

declare(strict_types=1);

namespace App\Tests\Integration\Web;

use App\Core\Connection\Engine;
use App\Tests\Integration\Driver\TargetDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * La mesure réelle d'un plan (EXPLAIN ANALYZE) sur les vraies cibles : une lecture simple seulement, à la demande, jamais une
 * écriture ni une instruction à effet de bord.
 */
#[Group('integration')]
final class MeasuredPlanWebTest extends WebApiTestCase
{
    private const JOIN = "select o.id, c.email from orders o join customers c on c.id = o.customer_id where o.status = 'shipped' order by o.ordered_at limit 50";

    private string $connectionId = '';
    private string $envVar = '';

    /**
     * @return iterable<string, array{string}>
     */
    public static function targets(): iterable
    {
        yield from TargetDatabase::relational();
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
     * @param array<string, mixed> $extra
     *
     * @return array<string, mixed>
     */
    private function measure(string $sql, array $extra = []): array
    {
        return $this->call('POST', '/web/connections/'.$this->connectionId.'/plan', ['sql' => $sql, 'measure' => true] + $extra);
    }

    private function pdo(): \PDO
    {
        $parameters = TargetDatabase::parameters($this->envVar);
        $driver = Engine::PostgreSql === TargetDatabase::engine($this->envVar) ? 'pgsql' : 'mysql';

        return new \PDO(\sprintf('%s:host=%s;port=%d;dbname=%s', $driver, $parameters->host, $parameters->port, $parameters->database), $parameters->username, $parameters->password(), [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
    }

    private function rowCount(string $sql): int
    {
        $statement = $this->pdo()->query($sql);
        self::assertInstanceOf(\PDOStatement::class, $statement);

        return (int) $statement->fetchColumn();
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

    private function auditUser(): \App\Entity\User
    {
        $users = self::getContainer()->get(\App\Repository\UserRepository::class);
        self::assertInstanceOf(\App\Repository\UserRepository::class, $users);
        $user = $users->findByLogin('alice');
        self::assertNotNull($user);

        return $user;
    }

    #[DataProvider('targets')]
    public function testAReadIsMeasuredOnRequest(string $envVar): void
    {
        $this->prepare($envVar);

        $body = $this->measure(self::JOIN);

        self::assertSame(200, $this->statusCode());
        $plan = self::arrayOf($body['plan']);
        self::assertTrue($plan['measured']);
        self::assertIsNumeric($plan['executionTimeMs']);
        $nodes = self::flatten($plan['root']);
        $orders = array_values(array_filter($nodes, static fn (array $n): bool => 'orders' === $n['relation'] || 'o' === $n['relation']));
        self::assertNotEmpty($orders);
        self::assertIsInt($orders[0]['actualRows']);
        self::assertIsInt($orders[0]['loops']);
        self::assertIsInt($orders[0]['rows']);
        // Les chiffres réels sont ceux de la base de test : 750 commandes « shipped » sur 3 000.
        self::assertGreaterThanOrEqual(1, $orders[0]['actualRows']);
        if (Engine::PostgreSql === TargetDatabase::engine($envVar)) {
            self::assertIsArray($orders[0]['buffers']);
        }
    }

    #[DataProvider('targets')]
    public function testWithoutTheFlagNothingIsMeasured(string $envVar): void
    {
        $this->prepare($envVar);

        $body = $this->call('POST', '/web/connections/'.$this->connectionId.'/plan', ['sql' => self::JOIN]);

        self::assertSame(200, $this->statusCode());
        self::assertArrayNotHasKey('measured', self::arrayOf($body['plan']));
        self::assertArrayNotHasKey('actualRows', self::arrayOf(self::arrayOf($body['plan'])['root']));
    }

    #[DataProvider('targets')]
    public function testFindingsAgreeWithTheMeasuredNumbers(string $envVar): void
    {
        $this->prepare($envVar);

        $body = $this->measure(self::JOIN);

        $plan = self::arrayOf($body['plan']);
        $expected = [];
        foreach (self::flatten($plan['root']) as $node) {
            if (\is_string($node['relation']) && \is_int($node['rows']) && \is_int($node['actualRows']) && $node['actualRows'] >= 1000 && max($node['rows'], $node['actualRows']) >= 100 * max(1, min($node['rows'], $node['actualRows']))) {
                $expected[$node['relation']] = true;
            }
        }
        $stale = [];
        foreach (self::listOf($body['findings']) as $finding) {
            $finding = self::arrayOf($finding);
            if ('stale_estimate' === $finding['rule']) {
                $stale[self::stringOf($finding['relation'])] = true;
                self::assertIsInt($finding['actualRows']);
            }
        }
        self::assertSame($expected, $stale);
    }

    #[DataProvider('targets')]
    public function testAWriteIsNeverMeasured(string $envVar): void
    {
        $this->prepare($envVar);
        $orders = $this->rowCount('select count(*) from orders');
        $items = $this->rowCount('select count(*) from order_items');
        $cancelled = $this->rowCount("select count(*) from orders where status = 'cancelled'");

        foreach (['delete from order_items', 'delete from order_items where quantity > 0', "update orders set status = 'cancelled'", 'insert into categories (name) select name from products', 'truncate order_items', 'analyze delete from order_items', 'explain analyze delete from order_items', 'with d as (select 1) delete from order_items'] as $sql) {
            $body = $this->measure($sql);
            self::assertSame(403, $this->statusCode(), $sql);
            self::assertSame('query_refused', $body['error'], $sql);

            $body = $this->measure($sql, ['write' => true]);
            self::assertSame(403, $this->statusCode(), $sql.' (write)');
            self::assertSame('query_refused', $body['error'], $sql);
        }

        self::assertSame($orders, $this->rowCount('select count(*) from orders'));
        self::assertSame($items, $this->rowCount('select count(*) from order_items'));
        self::assertSame($cancelled, $this->rowCount("select count(*) from orders where status = 'cancelled'"));
        self::assertSame(7000, $items);
    }

    #[DataProvider('targets')]
    public function testASelectIsNotMeasuredWhenWriteIsAskedToo(string $envVar): void
    {
        $this->prepare($envVar);

        $body = $this->measure('select * from orders where id = 5', ['write' => true]);

        self::assertSame(403, $this->statusCode());
        self::assertSame('query_refused', $body['error']);
    }

    #[DataProvider('targets')]
    public function testRefusesNestedExplainAndSeveralStatements(string $envVar): void
    {
        $this->prepare($envVar);

        foreach (['explain select 1', 'explain analyze select * from orders', 'select 1; select 2', 'select 1; delete from order_items', "select get_lock('measure', 0)"] as $sql) {
            $body = $this->measure($sql);
            if (Engine::PostgreSql === TargetDatabase::engine($envVar) && str_contains($sql, 'get_lock')) {
                continue;
            }
            self::assertSame(403, $this->statusCode(), $sql);
            self::assertSame('query_refused', $body['error'], $sql);
        }
        self::assertSame(7000, $this->rowCount('select count(*) from order_items'));
    }

    /**
     * Une fonction qui écrit n'est jamais jouée par la mesure : le garde refuse avant tout envoi (nextval avance une séquence).
     */
    public function testASequenceIsNeverAdvancedByAMeasure(): void
    {
        $this->prepare('TARGET_POSTGRES_URL');
        $name = 'tdb_measure_'.bin2hex(random_bytes(4));
        $pdo = $this->pdo();
        $pdo->exec('create sequence '.$name);
        try {
            self::assertSame(1, $this->rowCount("select nextval('".$name."')"));

            foreach (["select nextval('".$name."')", "select setval('".$name."', 500)", "with n as (select nextval('".$name."') as v) select v from n"] as $sql) {
                $body = $this->measure($sql);
                self::assertSame(403, $this->statusCode(), $sql);
                self::assertSame('query_refused', $body['error']);
            }

            self::assertSame(2, $this->rowCount("select nextval('".$name."')"), 'the sequence has not moved');
        } finally {
            $pdo->exec('drop sequence if exists '.$name);
        }
    }

    public function testAMeasuredQueryRunsInAReadOnlyTransaction(): void
    {
        $this->prepare('TARGET_POSTGRES_URL');
        $name = 'tdb_measure_'.bin2hex(random_bytes(4));
        $pdo = $this->pdo();
        $pdo->exec('create table '.$name.' (a int)');
        $pdo->exec('create function '.$name.'_w() returns int language plpgsql as $$ begin insert into '.$name.' values (1); return 1; end $$');
        try {
            $this->measure('select '.$name.'_w()');

            self::assertSame(0, $this->rowCount('select count(*) from '.$name), 'the engine itself refuses the write');
        } finally {
            $pdo->exec('drop function if exists '.$name.'_w()');
            $pdo->exec('drop table if exists '.$name);
        }
    }

    public function testClickHouseKeepsTheEstimateAndSaysItCannotMeasure(): void
    {
        $this->prepare('TARGET_CLICKHOUSE_URL');

        $body = $this->measure('select * from orders where customer_id = 5 order by ordered_at limit 10');

        self::assertSame(200, $this->statusCode());
        $plan = self::arrayOf($body['plan']);
        self::assertArrayNotHasKey('measured', $plan);
        self::assertSame(['status' => 'unsupported', 'reason' => 'engine'], $body['measure']);
    }

    public function testASuccessfulMeasureReportsItsStatus(): void
    {
        $this->prepare('TARGET_POSTGRES_URL');

        $body = $this->measure(self::JOIN);

        self::assertSame(['status' => 'measured'], $body['measure']);
    }

    public function testTheTimeoutIsBounded(): void
    {
        $this->prepare('TARGET_POSTGRES_URL');

        foreach ([1, 0, -5, 100_001, 600_000] as $timeout) {
            $body = $this->measure('select 1', ['timeoutMs' => $timeout]);
            self::assertSame(422, $this->statusCode(), (string) $timeout);
            self::assertSame('invalid_input', $body['error']);
        }
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function sleepers(): iterable
    {
        // Un produit cartésien de 343 milliards de lignes : seul le délai du moteur l'arrête (SLEEP, lui, rend 1 quand on l'interrompt).
        $heavy = 'select sum(a.quantity * b.quantity * c.quantity) from order_items a, order_items b, order_items c';
        yield 'MySQL' => ['TARGET_MYSQL_URL', $heavy];
        yield 'MySQL 26' => ['TARGET_MYSQL26_URL', $heavy];
        yield 'MariaDB' => ['TARGET_MARIADB_URL', $heavy];
        yield 'MariaDB 13' => ['TARGET_MARIADB13_URL', $heavy];
        yield 'PostgreSQL' => ['TARGET_POSTGRES_URL', $heavy];
    }

    #[DataProvider('sleepers')]
    public function testASlowMeasureIsCutByTheEngineTimeout(string $envVar, string $sql): void
    {
        $this->prepare($envVar);
        $started = microtime(true);

        $body = $this->measure($sql, ['timeoutMs' => 500]);

        self::assertLessThan(4.0, microtime(true) - $started);
        self::assertSame(408, $this->statusCode());
        self::assertSame('query_timeout', $body['error']);
    }

    public function testAMeasureIsAuditedWithAMaskedAndShortenedStatement(): void
    {
        $this->prepare('TARGET_POSTGRES_URL');
        $user = $this->auditUser();

        $this->measure("select id from orders where status = 'secret-value' and id > 42 /* private note */");

        $event = $this->auditEvents($user, 'query')[0];
        $details = $event->getDetails();
        self::assertSame('plan_measured', $details['purpose']);
        self::assertSame('select id from orders where status = ? and id > ?  ', $details['sql']);
        self::assertStringNotContainsString('secret-value', json_encode($details, \JSON_THROW_ON_ERROR));
        self::assertFalse($details['sqlTruncated']);
        self::assertSame('read_only', $details['mode']);
    }

    public function testALongStatementIsCutAtTwoHundredCharactersAfterMasking(): void
    {
        $this->prepare('TARGET_POSTGRES_URL');
        $user = $this->auditUser();
        $values = implode(', ', array_fill(0, 80, "'jean.dupont@example.org'"));

        $this->measure('select id from orders where status in ('.$values.')');

        $details = $this->auditEvents($user, 'query')[0]->getDetails();
        self::assertSame('plan_measured', $details['purpose']);
        self::assertIsString($details['sql']);
        self::assertLessThanOrEqual(200, mb_strlen($details['sql']));
        self::assertTrue($details['sqlTruncated']);
        self::assertStringNotContainsString('jean', $details['sql']);
    }

    public function testARefusedMeasureLeavesNoValueInTheJournal(): void
    {
        $this->prepare('TARGET_POSTGRES_URL');
        $user = $this->auditUser();

        $this->measure("delete from orders where status = 'secret-value'");

        self::assertSame(403, $this->statusCode());
        foreach ($this->auditEvents($user) as $event) {
            self::assertStringNotContainsString('secret-value', json_encode($event->getDetails(), \JSON_THROW_ON_ERROR));
        }
    }

    public function testAFailedMeasureKeepsTheEngineMessageOutOfTheJournal(): void
    {
        $this->prepare('TARGET_POSTGRES_URL');
        $user = $this->auditUser();

        $body = $this->measure("select * from orders where status = 'secret-value' and nonexistent_column = 1");

        self::assertSame(400, $this->statusCode());
        self::assertSame('query_failed', $body['error']);
        $details = $this->auditEvents($user, 'query')[0]->getDetails();
        self::assertSame('engine_error', $details['reason']);
        self::assertArrayNotHasKey('error', $details);
        self::assertStringNotContainsString('secret-value', json_encode($details, \JSON_THROW_ON_ERROR));
    }

    public function testAnEstimatedPlanStillKeepsItsOwnAuditEntry(): void
    {
        $this->prepare('TARGET_POSTGRES_URL');
        $user = $this->auditUser();

        $this->call('POST', '/web/connections/'.$this->connectionId.'/plan', ['sql' => 'select 1;']);

        $details = $this->auditEvents($user, 'query')[0]->getDetails();
        self::assertSame('plan', $details['purpose']);
        self::assertSame('select 1;', $details['sql']);
    }

    public function testNeedsASession(): void
    {
        $this->prepare('TARGET_POSTGRES_URL');
        $this->client->getCookieJar()->expire('TERRILDB_UNLOCK');

        $this->measure('select 1');

        self::assertSame(401, $this->statusCode());
    }
}
