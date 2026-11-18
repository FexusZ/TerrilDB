<?php

declare(strict_types=1);

namespace App\Tests\Integration\Web;

use App\Core\Connection\Engine;
use App\Driver\DbalConnector;
use App\Tests\Integration\Driver\TargetDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

#[Group('integration')]
final class SchemaAuditWebTest extends WebApiTestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function targets(): iterable
    {
        yield from TargetDatabase::all();
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function relationalTargets(): iterable
    {
        yield from TargetDatabase::relational();
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
        $this->call('GET', '/web/connections/00000000-0000-7000-8000-000000000000/audit');

        self::assertSame(401, $this->statusCode());
    }

    /**
     * Les index jamais lus dépendent de l'âge de la cible (au-delà de sept jours de collecte, un index de l'exemple que personne
     * n'a interrogé remonte à bon droit) : ce constat-là est jugé à part.
     *
     * @return list<mixed>
     */
    private static function withoutUnusedIndexes(mixed $findings): array
    {
        return array_values(array_filter(self::arrayOf($findings), static fn (mixed $f): bool => 'unused_index' !== self::arrayOf($f)['rule']));
    }

    /**
     * @return list<mixed>
     */
    private static function withoutUnusedChecks(mixed $checks): array
    {
        return array_values(array_filter(self::arrayOf($checks), static fn (mixed $c): bool => 'unused_index' !== self::arrayOf($c)['rule']));
    }

    #[DataProvider('targets')]
    public function testTheSampleSchemaIsClean(string $envVar): void
    {
        $this->seedAndLogin();
        $id = $this->saveTarget($envVar);

        $body = $this->call('GET', '/web/connections/'.$id.'/audit');

        self::assertSame(200, $this->statusCode());
        self::assertSame(5, $body['tablesAudited']);
        self::assertFalse($body['truncated']);
        self::assertSame([], self::withoutUnusedIndexes($body['findings']));
    }

    public function testReportsDeadTuplesAndStaleStatisticsOfAnActualPostgreSqlTable(): void
    {
        $this->seedAndLogin();
        $id = $this->saveTarget('TARGET_POSTGRES_URL');
        $connection = (new DbalConnector())->connect(TargetDatabase::engine('TARGET_POSTGRES_URL'), TargetDatabase::parameters('TARGET_POSTGRES_URL'));

        try {
            // Sans autovacuum : rien ne passe derrière nous le temps de lire.
            $connection->executeStatement('CREATE TABLE audit_probe_ops (id serial PRIMARY KEY, pad text) WITH (autovacuum_enabled = false)');
            $connection->executeStatement("INSERT INTO audit_probe_ops (pad) SELECT repeat('x', 50) FROM generate_series(1, 30000)");
            $connection->executeStatement('DELETE FROM audit_probe_ops WHERE id <= 20000');

            $rules = [];
            for ($attempt = 0; $attempt < 30 && [] === $rules; ++$attempt) {
                $connection->fetchOne('SELECT pg_stat_force_next_flush()');
                $body = $this->call('GET', '/web/connections/'.$id.'/audit');
                foreach (array_map(self::arrayOf(...), self::arrayOf($body['findings'])) as $finding) {
                    if ('audit_probe_ops' === $finding['table'] && 'dead_tuples' === $finding['rule']) {
                        $rules[] = $finding;
                    }
                }
                usleep(300000);
            }
        } finally {
            $connection->executeStatement('DROP TABLE IF EXISTS audit_probe_ops');
            $connection->close();
        }

        self::assertCount(1, $rules);
        self::assertSame('info', $rules[0]['severity']);
        self::assertStringContainsString('20000 tuples morts pour 10000 vivants', self::stringOf($rules[0]['detail']));
        self::assertStringStartsWith('VACUUM (ANALYZE) "audit_probe_ops";', self::stringOf($rules[0]['suggestion']));
        self::assertSame([], self::withoutUnusedChecks($body['checks']));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function mysqlFamily(): iterable
    {
        yield 'MySQL' => ['TARGET_MYSQL_URL'];
        yield 'MySQL 26' => ['TARGET_MYSQL26_URL'];
        yield 'MariaDB' => ['TARGET_MARIADB_URL'];
        yield 'MariaDB 13' => ['TARGET_MARIADB13_URL'];
    }

    #[DataProvider('mysqlFamily')]
    public function testReportsAViewWhoseCreatorAccountNoLongerExists(string $envVar): void
    {
        $this->seedAndLogin();
        $id = $this->saveTarget($envVar);
        $connection = (new DbalConnector())->connect(TargetDatabase::engine($envVar), TargetDatabase::parameters($envVar));

        try {
            $connection->executeStatement("CREATE USER 'audit_gone'@'localhost'");
            $connection->executeStatement("CREATE DEFINER = 'audit_gone'@'localhost' SQL SECURITY DEFINER VIEW audit_probe_v AS SELECT id FROM categories");
            $connection->executeStatement("DROP USER 'audit_gone'@'localhost'");

            $body = $this->call('GET', '/web/connections/'.$id.'/audit');
        } finally {
            $connection->executeStatement('DROP VIEW IF EXISTS audit_probe_v');
            $connection->executeStatement("DROP USER IF EXISTS 'audit_gone'@'localhost'");
            $connection->close();
        }

        $findings = array_values(array_filter(array_map(self::arrayOf(...), self::arrayOf($body['findings'])), static fn (array $f): bool => 'orphan_definer' === $f['rule']));
        self::assertCount(1, $findings);
        self::assertSame('audit_probe_v', $findings[0]['table']);
        self::assertSame('warning', $findings[0]['severity']);
        self::assertSame('vue créée par « audit_gone@localhost », compte inexistant', $findings[0]['detail']);
        self::assertSame([], self::withoutUnusedChecks($body['checks']));
    }

    /**
     * @return iterable<string, array{string, ?string, ?string}>
     */
    public static function usageChecks(): iterable
    {
        // Les cibles viennent de démarrer : MySQL et PostgreSQL disent que la collecte est trop récente (ni constat ni erreur),
        // MariaDB que ses compteurs sont éteints, ClickHouse n'a pas de règle.
        yield 'MySQL' => ['TARGET_MYSQL_URL', 'too_short', null];
        yield 'MySQL 26' => ['TARGET_MYSQL26_URL', 'too_short', null];
        yield 'MariaDB' => ['TARGET_MARIADB_URL', 'unavailable', 'performance_schema_off'];
        yield 'MariaDB 13' => ['TARGET_MARIADB13_URL', 'unavailable', 'performance_schema_off'];
        yield 'PostgreSQL' => ['TARGET_POSTGRES_URL', 'too_short', null];
        yield 'ClickHouse' => ['TARGET_CLICKHOUSE_URL', null, null];
    }

    #[DataProvider('usageChecks')]
    public function testUnusedIndexRuleSaysWhyItCouldNotJudge(string $envVar, ?string $state, ?string $reason): void
    {
        $this->seedAndLogin();
        $id = $this->saveTarget($envVar);

        $body = $this->call('GET', '/web/connections/'.$id.'/audit');

        self::assertSame(200, $this->statusCode());
        self::assertSame([], self::withoutUnusedIndexes($body['findings']));
        $checks = array_map(self::arrayOf(...), self::arrayOf($body['checks']));
        if (null === $state || ('too_short' === $state && [] === $checks)) {
            // Pas de règle (ClickHouse), ou une cible qui tourne depuis plus de sept jours : la règle a pu juger.
            self::assertSame([], $checks);

            return;
        }
        self::assertCount(1, $checks);
        self::assertSame('unused_index', $checks[0]['rule']);
        self::assertSame($state, $checks[0]['state']);
        self::assertSame($reason, $checks[0]['reason']);
    }

    public function testFindsWhatIsWrongInAnActualSchema(): void
    {
        $this->seedAndLogin();
        $id = $this->saveTarget('TARGET_POSTGRES_URL');
        $connection = (new DbalConnector())->connect(TargetDatabase::engine('TARGET_POSTGRES_URL'), TargetDatabase::parameters('TARGET_POSTGRES_URL'));

        try {
            $connection->executeStatement('CREATE TABLE audit_probe (note text, category_id integer REFERENCES categories (id))');
            $connection->executeStatement('CREATE TABLE audit_probe_ix (id integer PRIMARY KEY, a integer)');
            $connection->executeStatement('CREATE INDEX audit_probe_ix_a ON audit_probe_ix (a)');
            $connection->executeStatement('CREATE INDEX audit_probe_ix_ab ON audit_probe_ix (a, id)');

            $body = $this->call('GET', '/web/connections/'.$id.'/audit');
        } finally {
            $connection->executeStatement('DROP TABLE IF EXISTS audit_probe, audit_probe_ix');
            $connection->close();
        }

        self::assertSame(200, $this->statusCode());
        $findings = array_map(self::arrayOf(...), self::arrayOf($body['findings']));
        $summary = array_map(static fn (array $f): string => \sprintf('%s:%s', \is_string($f['rule']) ? $f['rule'] : '', \is_string($f['table']) ? $f['table'] : ''), $findings);
        self::assertContains('missing_primary_key:audit_probe', $summary);
        self::assertContains('foreign_key_without_index:audit_probe', $summary);
        self::assertContains('redundant_index:audit_probe_ix', $summary);
        $fk = $findings[array_search('foreign_key_without_index:audit_probe', $summary, true)];
        self::assertSame('warning', $fk['severity']);
        self::assertSame(['category_id'], $fk['columns']);
        self::assertSame('CREATE INDEX idx_audit_probe_category_id ON "audit_probe" ("category_id");', $fk['suggestion']);
        $redundant = $findings[array_search('redundant_index:audit_probe_ix', $summary, true)];
        self::assertSame('audit_probe_ix_a', $redundant['index']);
        self::assertSame('audit_probe_ix_ab', $redundant['coveredBy']);
        self::assertSame(['rule', 'severity', 'table', 'columns', 'index', 'coveredBy', 'suggestion', 'detail'], array_keys($fk));
    }

    public function testFindsAForeignKeyThatForcesAConversionButNotOneThatKeepsItsIndex(): void
    {
        $this->seedAndLogin();
        $id = $this->saveTarget('TARGET_POSTGRES_URL');
        $connection = (new DbalConnector())->connect(TargetDatabase::engine('TARGET_POSTGRES_URL'), TargetDatabase::parameters('TARGET_POSTGRES_URL'));

        try {
            $connection->executeStatement('CREATE TABLE audit_probe_num (id numeric PRIMARY KEY)');
            $connection->executeStatement('CREATE TABLE audit_probe_big (id bigint PRIMARY KEY)');
            $connection->executeStatement('CREATE TABLE audit_probe_small (id integer PRIMARY KEY, num_id integer REFERENCES audit_probe_num (id), big_id integer REFERENCES audit_probe_big (id))');
            $connection->executeStatement('CREATE INDEX audit_probe_small_num ON audit_probe_small (num_id)');
            $connection->executeStatement('CREATE INDEX audit_probe_small_big ON audit_probe_small (big_id)');

            $body = $this->call('GET', '/web/connections/'.$id.'/audit');
        } finally {
            $connection->executeStatement('DROP TABLE IF EXISTS audit_probe_small, audit_probe_num, audit_probe_big');
            $connection->close();
        }

        self::assertSame(200, $this->statusCode());
        $findings = array_values(array_filter(array_map(self::arrayOf(...), self::arrayOf($body['findings'])), static fn (array $f): bool => 'foreign_key_type_mismatch' === $f['rule']));
        // integer -> numeric convertit la colonne (index perdu) ; integer -> bigint, non (index gardé).
        self::assertCount(1, $findings);
        self::assertSame('audit_probe_small', $findings[0]['table']);
        self::assertSame(['num_id'], $findings[0]['columns']);
        self::assertSame('integer → numeric', $findings[0]['detail']);
        self::assertSame('ALTER TABLE "audit_probe_small" ALTER COLUMN "num_id" TYPE numeric;', $findings[0]['suggestion']);
    }

    #[DataProvider('relationalTargets')]
    public function testFindsAnUuidStoredAsTextKey(string $envVar): void
    {
        $this->seedAndLogin();
        $id = $this->saveTarget($envVar);
        $connection = (new DbalConnector())->connect(TargetDatabase::engine($envVar), TargetDatabase::parameters($envVar));

        try {
            $connection->executeStatement('CREATE TABLE audit_probe_uuid (id char(36) PRIMARY KEY, label varchar(20))');
            $connection->executeStatement('CREATE TABLE audit_probe_ok (id char(32) PRIMARY KEY)');

            $body = $this->call('GET', '/web/connections/'.$id.'/audit');
        } finally {
            $connection->executeStatement('DROP TABLE IF EXISTS audit_probe_uuid');
            $connection->executeStatement('DROP TABLE IF EXISTS audit_probe_ok');
            $connection->close();
        }

        self::assertSame(200, $this->statusCode());
        $findings = array_values(array_filter(array_map(self::arrayOf(...), self::arrayOf($body['findings'])), static fn (array $f): bool => 'text_primary_key' === $f['rule']));
        self::assertCount(1, $findings);
        self::assertSame('audit_probe_uuid', $findings[0]['table']);
        self::assertSame('warning', $findings[0]['severity']);
        self::assertSame(['id'], $findings[0]['columns']);
        self::assertIsString($findings[0]['detail']);
        self::assertMatchesRegularExpression('/^(char|character)\(36\)$/i', $findings[0]['detail']);
        if (Engine::PostgreSql === TargetDatabase::engine($envVar)) {
            self::assertSame('ALTER TABLE "audit_probe_uuid" ALTER COLUMN "id" TYPE uuid USING "id"::uuid;', $findings[0]['suggestion']);
        } else {
            self::assertNull($findings[0]['suggestion']);
        }
    }

    #[DataProvider('relationalTargets')]
    public function testFindsAMoneyColumnStoredAsFloatingPoint(string $envVar): void
    {
        $this->seedAndLogin();
        $id = $this->saveTarget($envVar);
        $engine = TargetDatabase::engine($envVar);
        $connection = (new DbalConnector())->connect($engine, TargetDatabase::parameters($envVar));

        try {
            $connection->executeStatement('CREATE TABLE audit_probe_money (id integer PRIMARY KEY, price double precision NOT NULL, price_cents double precision, weight double precision)');
            $body = $this->call('GET', '/web/connections/'.$id.'/audit');
        } finally {
            $connection->executeStatement('DROP TABLE IF EXISTS audit_probe_money');
            $connection->close();
        }

        self::assertSame(200, $this->statusCode());
        $findings = array_values(array_filter(array_map(self::arrayOf(...), self::arrayOf($body['findings'])), static fn (array $f): bool => 'float_amount' === $f['rule']));
        self::assertCount(1, $findings);
        self::assertSame('audit_probe_money', $findings[0]['table']);
        self::assertSame(['price'], $findings[0]['columns']);
        self::assertSame('info', $findings[0]['severity']);
        self::assertSame(Engine::PostgreSql === $engine ? 'ALTER TABLE "audit_probe_money" ALTER COLUMN "price" TYPE numeric(12,2);' : 'ALTER TABLE `audit_probe_money` MODIFY `price` DECIMAL(12,2) NOT NULL;', $findings[0]['suggestion']);
    }

    public function testAnotherAccountsConnectionIsNotFound(): void
    {
        $this->seedAndLogin('alice');
        $id = $this->saveTarget('TARGET_POSTGRES_URL');
        $this->call('POST', '/web/logout');
        $this->seedUser('bob');
        $this->login('bob');

        $this->call('GET', '/web/connections/'.$id.'/audit');

        self::assertSame(404, $this->statusCode());
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function counterTargets(): iterable
    {
        yield 'MySQL' => ['TARGET_MYSQL_URL', 'CREATE TABLE audit_counter (id SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY) AUTO_INCREMENT = 62000'];
        yield 'MySQL 26' => ['TARGET_MYSQL26_URL', 'CREATE TABLE audit_counter (id SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY) AUTO_INCREMENT = 62000'];
        yield 'MariaDB' => ['TARGET_MARIADB_URL', 'CREATE TABLE audit_counter (id SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY) AUTO_INCREMENT = 62000'];
        yield 'MariaDB 13' => ['TARGET_MARIADB13_URL', 'CREATE TABLE audit_counter (id SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY) AUTO_INCREMENT = 62000'];
        yield 'PostgreSQL' => ['TARGET_POSTGRES_URL', 'CREATE TABLE audit_counter (id smallint GENERATED ALWAYS AS IDENTITY (START WITH 31000) PRIMARY KEY)'];
    }

    #[DataProvider('counterTargets')]
    public function testFlagsACounterCloseToTheLimitOfItsType(string $envVar, string $create): void
    {
        $this->seedAndLogin();
        $id = $this->saveTarget($envVar);
        $connection = (new DbalConnector())->connect(TargetDatabase::engine($envVar), TargetDatabase::parameters($envVar));

        try {
            $connection->executeStatement('DROP TABLE IF EXISTS audit_counter');
            $connection->executeStatement($create);
            $connection->executeStatement(str_contains($create, 'IDENTITY') ? 'INSERT INTO audit_counter DEFAULT VALUES' : 'INSERT INTO audit_counter VALUES (NULL)');
            $body = $this->call('GET', '/web/connections/'.$id.'/audit');
        } finally {
            $connection->executeStatement('DROP TABLE IF EXISTS audit_counter');
            $connection->close();
        }

        self::assertSame(200, $this->statusCode());
        $findings = array_values(array_filter(array_map(self::arrayOf(...), self::arrayOf($body['findings'])), static fn (array $f): bool => 'counter_near_limit' === $f['rule']));
        self::assertCount(1, $findings);
        self::assertSame('audit_counter', $findings[0]['table']);
        self::assertSame(['id'], $findings[0]['columns']);
        self::assertSame('critical', $findings[0]['severity']);
        self::assertIsString($findings[0]['suggestion']);
        self::assertStringContainsString('ALTER TABLE', $findings[0]['suggestion']);
        self::assertIsString($findings[0]['detail']);
        self::assertStringContainsString('(9', $findings[0]['detail']);
    }
}
