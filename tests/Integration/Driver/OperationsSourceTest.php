<?php

declare(strict_types=1);

namespace App\Tests\Integration\Driver;

use App\Core\Connection\ConnectionParameters;
use App\Core\Connection\Engine;
use App\Core\Optimizer\FindingRule;
use App\Core\Optimizer\SchemaAuditor;
use App\Core\Schema\DefinerObject;
use App\Core\Schema\OperationsSource;
use App\Core\Schema\TableUpkeep;
use App\Core\Schema\UpkeepReport;
use App\Driver\DbalConnector;
use App\Driver\MySql\MySqlIntrospector;
use App\Driver\PostgreSql\PostgreSqlIntrospector;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * L'audit d'exploitation lu sur les vrais moteurs. Chaque objet créé ici est jetable et retiré (y compris le compte créateur),
 * jamais laissé sur une cible partagée.
 */
#[Group('integration')]
final class OperationsSourceTest extends TestCase
{
    private const MIB = 1048576;

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

    private function connect(string $envVar, ?ConnectionParameters $as = null): Connection
    {
        try {
            return (new DbalConnector())->connect(TargetDatabase::engine($envVar), $as ?? TargetDatabase::parameters($envVar));
        } catch (\Throwable) {
            self::markTestSkipped($envVar.' is unreachable (docker compose --profile targets up -d --wait).');
        }
    }

    private function source(string $envVar, Connection $connection): OperationsSource
    {
        return Engine::PostgreSql === TargetDatabase::engine($envVar) ? new PostgreSqlIntrospector($connection) : new MySqlIntrospector($connection);
    }

    /**
     * @return list<string>
     */
    private static function orphanNames(OperationsSource $source): array
    {
        return array_map(static fn (DefinerObject $o): string => $o->name, $source->orphanDefiners()->objects);
    }

    /**
     * @return array<string, TableUpkeep>
     */
    private static function byTable(UpkeepReport $report): array
    {
        $tables = [];
        foreach ($report->tables as $table) {
            $tables[$table->table] = $table;
        }

        return $tables;
    }

    public function testPostgreSqlCountsDeadTuplesAndRowsChangedSinceTheLastAnalyze(): void
    {
        $connection = $this->connect('TARGET_POSTGRES_URL');
        $source = new PostgreSqlIntrospector($connection);

        try {
            // Sans autovacuum : rien ne passe derrière nous le temps de lire.
            $connection->executeStatement('CREATE TABLE ops_probe (id serial PRIMARY KEY, pad text) WITH (autovacuum_enabled = false)');
            $connection->executeStatement("INSERT INTO ops_probe (pad) SELECT repeat('x', 50) FROM generate_series(1, 30000)");
            $connection->executeStatement('DELETE FROM ops_probe WHERE id <= 20000');

            $probe = $this->waitFor(static function () use ($connection, $source): ?TableUpkeep {
                $connection->fetchOne('SELECT pg_stat_force_next_flush()');
                $found = self::byTable($source->upkeep())['ops_probe'] ?? null;

                return null !== $found && ($found->deadRows ?? 0) >= 20000 ? $found : null;
            });

            self::assertSame(20000, $probe->deadRows);
            self::assertSame(10000, $probe->liveRows);
            self::assertGreaterThanOrEqual(50000, $probe->modifiedSinceAnalyze);
            self::assertNull($probe->lastAnalyze);
            self::assertNull($probe->lastVacuum);

            $rules = array_map(static fn ($f): FindingRule => $f->rule, (new SchemaAuditor())->operations(Engine::PostgreSql, UpkeepReport::of([$probe]), null));
            self::assertSame([FindingRule::StaleStatistics, FindingRule::DeadTuples], $rules);

            // Après ANALYZE, les statistiques ne sont plus périmées et la date est donnée.
            $connection->executeStatement('ANALYZE ops_probe');
            $analyzed = $this->waitFor(static function () use ($connection, $source): ?TableUpkeep {
                $connection->fetchOne('SELECT pg_stat_force_next_flush()');
                $found = self::byTable($source->upkeep())['ops_probe'] ?? null;

                return null !== $found && null !== $found->lastAnalyze ? $found : null;
            });
            self::assertSame(0, $analyzed->modifiedSinceAnalyze);
            self::assertSame([FindingRule::DeadTuples], array_map(static fn ($f): FindingRule => $f->rule, (new SchemaAuditor())->operations(Engine::PostgreSql, UpkeepReport::of([$analyzed]), null)));
        } finally {
            $connection->executeStatement('DROP TABLE IF EXISTS ops_probe');
            $connection->close();
        }
    }

    public function testPostgreSqlSampleTablesAreNeitherStaleNorBloated(): void
    {
        $connection = $this->connect('TARGET_POSTGRES_URL');
        $report = (new PostgreSqlIntrospector($connection))->upkeep();
        $connection->close();

        self::assertTrue($report->available);
        self::assertArrayHasKey('order_items', self::byTable($report));
        self::assertSame([], (new SchemaAuditor())->operations(Engine::PostgreSql, $report, null));
    }

    #[DataProvider('mysqlFamily')]
    public function testSampleTablesHaveNothingReclaimableAndTheirDefinersExist(string $envVar): void
    {
        $connection = $this->connect($envVar);
        $source = $this->source($envVar, $connection);
        $upkeep = $source->upkeep();
        $definers = $source->orphanDefiners();
        $connection->close();

        self::assertTrue($upkeep->available);
        self::assertContains('order_items', array_keys(self::byTable($upkeep)));
        self::assertTrue($definers->available);
        self::assertSame([], (new SchemaAuditor())->operations(TargetDatabase::engine($envVar), $upkeep, $definers));
    }

    #[DataProvider('mysqlFamily')]
    public function testReclaimableSpaceAppearsOnceTheDeletedRowsAreGone(string $envVar): void
    {
        $connection = $this->connect($envVar);
        $source = $this->source($envVar, $connection);

        try {
            $connection->executeStatement('CREATE TABLE ops_probe (id int AUTO_INCREMENT PRIMARY KEY, pad char(200)) ENGINE=InnoDB');
            $connection->executeStatement("INSERT INTO ops_probe (pad) SELECT repeat('x', 200) FROM information_schema.columns a, information_schema.columns b LIMIT 400000");
            $connection->executeStatement('DELETE FROM ops_probe WHERE id > 1000');

            // Le nettoyage des lignes supprimées se fait en arrière-plan : on attend qu'il ait rendu les extensions.
            $probe = $this->waitFor(static function () use ($connection, $source): ?TableUpkeep {
                $connection->fetchAllAssociative('ANALYZE TABLE ops_probe');
                $found = self::byTable($source->upkeep())['ops_probe'] ?? null;

                return null !== $found && ($found->freeBytes ?? 0) >= 64 * self::MIB ? $found : null;
            }, 60);

            $findings = (new SchemaAuditor())->operations(TargetDatabase::engine($envVar), UpkeepReport::of([$probe]), null);
            self::assertSame([FindingRule::ReclaimableSpace], array_map(static fn ($f): FindingRule => $f->rule, $findings));

            // Le remède proposé se joue tel quel, et rend la place.
            $connection->fetchAllAssociative('OPTIMIZE TABLE ops_probe');
            $after = self::byTable($source->upkeep())['ops_probe'] ?? null;
            self::assertNotNull($after);
            self::assertLessThan(64 * self::MIB, $after->freeBytes);
        } finally {
            $connection->executeStatement('DROP TABLE IF EXISTS ops_probe');
            $connection->close();
        }
    }

    #[DataProvider('mysqlFamily')]
    public function testObjectsOfAVanishedAccountAreFoundAndAnEventIsFixedInPlace(string $envVar): void
    {
        $connection = $this->connect($envVar);
        $source = $this->source($envVar, $connection);
        $engine = TargetDatabase::engine($envVar);
        $definer = "'ops_gone'@'localhost'";

        try {
            $connection->executeStatement("CREATE USER {$definer} IDENTIFIED BY '".bin2hex(random_bytes(8))."'");
            $connection->executeStatement('CREATE TABLE ops_probe_t (id int PRIMARY KEY, note int)');
            $connection->executeStatement("CREATE DEFINER = {$definer} SQL SECURITY DEFINER VIEW ops_probe_v AS SELECT id FROM ops_probe_t");
            $connection->executeStatement("CREATE DEFINER = {$definer} SQL SECURITY INVOKER VIEW ops_probe_vi AS SELECT id FROM ops_probe_t");
            $connection->executeStatement("CREATE DEFINER = {$definer} PROCEDURE ops_probe_p() SQL SECURITY DEFINER SELECT 1");
            $connection->executeStatement("CREATE DEFINER = {$definer} FUNCTION ops_probe_f() RETURNS int DETERMINISTIC SQL SECURITY DEFINER RETURN 1");
            $connection->executeStatement("CREATE DEFINER = {$definer} TRIGGER ops_probe_trg BEFORE INSERT ON ops_probe_t FOR EACH ROW SET NEW.note = 1");
            $connection->executeStatement("CREATE DEFINER = {$definer} EVENT ops_probe_e ON SCHEDULE EVERY 1 YEAR DISABLE DO SELECT 1");

            // Tant que le compte existe, rien à signaler.
            self::assertSame([], $source->orphanDefiners()->objects);

            $connection->executeStatement("DROP USER {$definer}");

            $report = $source->orphanDefiners();
            self::assertTrue($report->available);
            $found = array_map(static fn (DefinerObject $o): string => $o->kind->value.':'.$o->name.':'.($o->table ?? ''), $report->objects);
            sort($found);
            // La vue en SQL SECURITY INVOKER n'emprunte pas l'identité du créateur : jamais signalée.
            self::assertSame(['event:ops_probe_e:', 'function:ops_probe_f:', 'procedure:ops_probe_p:', 'trigger:ops_probe_trg:ops_probe_t', 'view:ops_probe_v:'], $found);
            self::assertSame('ops_gone@localhost', $report->objects[0]->definer);

            $findings = (new SchemaAuditor())->operations($engine, null, $report);
            self::assertCount(5, $findings);
            $event = array_values(array_filter($findings, static fn ($f): bool => 'ops_probe_e' === $f->index))[0];
            self::assertSame(FindingRule::OrphanDefiner, $event->rule);

            // La correction proposée pour l'événement (en gardant son état) s'exécute et le réattribue à un compte qui existe.
            self::assertStringContainsString('ALTER DEFINER = CURRENT_USER EVENT `ops_probe_e` ENABLE', (string) $event->suggestion);
            $connection->executeStatement('ALTER DEFINER = CURRENT_USER EVENT `ops_probe_e` DISABLE');
            $left = self::orphanNames($source);
            self::assertNotContains('ops_probe_e', $left);
            self::assertContains('ops_probe_v', $left);
        } finally {
            $connection->executeStatement('DROP EVENT IF EXISTS ops_probe_e');
            $connection->executeStatement('DROP FUNCTION IF EXISTS ops_probe_f');
            $connection->executeStatement('DROP PROCEDURE IF EXISTS ops_probe_p');
            $connection->executeStatement('DROP VIEW IF EXISTS ops_probe_v, ops_probe_vi');
            $connection->executeStatement('DROP TABLE IF EXISTS ops_probe_t');
            $connection->executeStatement("DROP USER IF EXISTS {$definer}");
            $connection->close();
        }
    }

    #[DataProvider('mysqlFamily')]
    public function testAnAccountThatCannotReadTheCatalogGetsAReasonNotAnError(string $envVar): void
    {
        $admin = $this->connect($envVar);
        $base = TargetDatabase::parameters($envVar);
        $password = bin2hex(random_bytes(8));
        $account = "'ops_reader'@'%'";

        try {
            $admin->executeStatement("CREATE USER {$account} IDENTIFIED BY '{$password}'");
            $admin->executeStatement("GRANT SELECT ON `{$base->database}`.* TO {$account}");
            $limited = $this->connect($envVar, new ConnectionParameters($base->host, $base->port, $base->database, 'ops_reader', $password));
            $source = $this->source($envVar, $limited);

            $definers = $source->orphanDefiners();
            self::assertFalse($definers->available);
            self::assertSame('permission_denied', $definers->reason);
            $upkeep = $source->upkeep();
            self::assertFalse($upkeep->available);
            self::assertSame('permission_denied', $upkeep->reason);

            $engine = TargetDatabase::engine($envVar);
            self::assertSame([], (new SchemaAuditor())->operations($engine, $upkeep, $definers));
            self::assertCount(2, (new SchemaAuditor())->operationsChecks($engine, $upkeep, $definers));
            $limited->close();
        } finally {
            $admin->executeStatement("DROP USER IF EXISTS {$account}");
            $admin->close();
        }
    }

    /**
     * @template T of object
     *
     * @param callable(): ?T $probe
     *
     * @return T
     */
    private function waitFor(callable $probe, int $seconds = 10): object
    {
        $deadline = microtime(true) + $seconds;
        do {
            $value = $probe();
            if (null !== $value) {
                return $value;
            }
            usleep(300000);
        } while (microtime(true) < $deadline);

        self::fail('The engine did not reach the expected state in time.');
    }
}
