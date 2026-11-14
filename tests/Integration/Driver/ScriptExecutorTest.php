<?php

declare(strict_types=1);

namespace App\Tests\Integration\Driver;

use App\Core\Connection\Engine;
use App\Core\Driver\TargetConnection;
use App\Core\Guard\ScriptAnalyzer;
use App\Core\Query\Script\Exception\ScriptDryRunUnavailableException;
use App\Core\Query\Script\ScriptOptions;
use App\Core\Query\Script\ScriptResult;
use App\Core\Query\Script\ScriptService;
use App\Core\Query\Script\ScriptStatus;
use App\Core\Query\Script\ScriptStepStatus;
use App\Core\Query\WritePermissions;
use App\Driver\ClickHouse\ClickHouseDriver;
use App\Driver\DbalConnector;
use App\Driver\MySql\MySqlDriver;
use App\Driver\PostgreSql\PostgreSqlDriver;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\HttpClient;

/**
 * Le script rejoué contre les vrais moteurs, sur des tables jetables (jamais le schéma partagé).
 */
#[Group('integration')]
final class ScriptExecutorTest extends TestCase
{
    private ?TargetConnection $target = null;
    private ?Connection $raw = null;
    /** @var list<string> */
    private array $tables = [];
    private string $envVar = '';

    protected function tearDown(): void
    {
        foreach ($this->tables as $table) {
            try {
                $this->raw?->executeStatement('DROP TABLE IF EXISTS '.$table);
                if (null !== $this->target && Engine::ClickHouse === $this->target->engine) {
                    $this->target->script()->execute([new \App\Core\Query\Script\ScriptStatement(0, 'DROP TABLE IF EXISTS '.$table, \App\Core\Guard\StatementKind::Schema)], 30_000, false);
                }
            } catch (\Throwable) {
            }
        }
        $this->target?->close();
        $this->raw?->close();
        parent::tearDown();
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function relational(): iterable
    {
        return TargetDatabase::relational();
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

    private function open(string $envVar): void
    {
        $this->envVar = $envVar;
        $engine = TargetDatabase::engine($envVar);
        $parameters = TargetDatabase::parameters($envVar);
        $this->target = match ($engine) {
            Engine::PostgreSql => (new PostgreSqlDriver(new DbalConnector()))->connect($engine, $parameters),
            Engine::ClickHouse => (new ClickHouseDriver(HttpClient::create()))->connect($engine, $parameters),
            default => (new MySqlDriver(new DbalConnector()))->connect($engine, $parameters),
        };
        if (Engine::ClickHouse !== $engine) {
            $this->raw = (new DbalConnector())->connect($engine, $parameters);
        }
    }

    /** Crée une table jetable (id entier clé primaire, v texte) avec les lignes données. */
    private function table(string $name, bool $rows = false): string
    {
        $table = 'tdb_scr_'.$name.'_'.bin2hex(random_bytes(3));
        $this->tables[] = $table;
        if (null === $this->raw) {
            $this->target?->script()->execute([new \App\Core\Query\Script\ScriptStatement(0, \sprintf('CREATE TABLE %s (id Int32, v String) ENGINE = MergeTree ORDER BY id', $table), \App\Core\Guard\StatementKind::Schema)], 30_000, false);
        } else {
            $this->raw->executeStatement(\sprintf('CREATE TABLE %s (id int NOT NULL PRIMARY KEY, v varchar(20))', $table));
        }
        if ($rows) {
            $this->script(\sprintf("INSERT INTO %s VALUES (1, 'a')", $table));
        }

        return $table;
    }

    private function script(string $script, bool $dryRun = false, int $timeoutMs = 60_000): ScriptResult
    {
        \assert(null !== $this->target);

        return (new ScriptService(new ScriptAnalyzer()))->execute($this->target, $script, new ScriptOptions(WritePermissions::all(), $timeoutMs, $dryRun), true);
    }

    /** @return list<int> */
    private function ids(string $table): array
    {
        if (null === $this->raw) {
            $result = (new \App\Driver\ClickHouse\ClickHouseHttp(HttpClient::create(), TargetDatabase::parameters($this->envVar)))->query('SELECT id FROM '.$table.' ORDER BY id');

            return array_map(static fn (array $row): int => self::int($row[0]), $result->rows);
        }

        return array_map(self::int(...), $this->raw->fetchFirstColumn(\sprintf('SELECT id FROM %s ORDER BY id', $table)));
    }

    private function scalar(string $sql): int
    {
        return self::int($this->raw?->fetchOne($sql));
    }

    private static function int(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }

    /**
     * @return list<string>
     */
    private static function statuses(ScriptResult $result): array
    {
        return array_map(static fn ($step): string => $step->status->value, $result->steps);
    }

    #[DataProvider('relational')]
    public function testASuccessfulScriptIsCommittedStatementByStatement(string $envVar): void
    {
        $this->open($envVar);
        $table = $this->table('ok');

        $result = $this->script(\sprintf("INSERT INTO %1\$s VALUES (1, 'a'); INSERT INTO %1\$s VALUES (2, 'b'); UPDATE %1\$s SET v = 'z' WHERE id = 2; SELECT * FROM %1\$s", $table));

        self::assertSame(ScriptStatus::Committed, $result->status);
        self::assertSame(['applied', 'applied', 'applied', 'applied'], self::statuses($result));
        self::assertSame([1, 1, 1, null], array_map(static fn ($s): ?int => $s->affectedRows, $result->steps));
        self::assertSame(2, $result->steps[3]->returnedRows);
        self::assertSame([1, 2], $this->ids($table));
        self::assertNull($result->failureReason);
    }

    #[DataProvider('relational')]
    public function testAnErrorInTheMiddleStopsAndCancelsEverythingInTheTransaction(string $envVar): void
    {
        $this->open($envVar);
        $table = $this->table('fail', true);

        $result = $this->script(\sprintf("INSERT INTO %1\$s VALUES (2, 'b'); INSERT INTO %1\$s VALUES (1, 'dup'); INSERT INTO %1\$s VALUES (3, 'c');", $table));

        self::assertSame(ScriptStatus::RolledBack, $result->status);
        self::assertSame(['rolled_back', 'failed', 'not_run'], self::statuses($result));
        $failed = $result->failedStep();
        self::assertNotNull($failed);
        self::assertSame(1, $failed->index);
        self::assertNotSame('', (string) $failed->error);
        self::assertSame('engine_error', $result->failureReason);
        self::assertSame([1], $this->ids($table));
    }

    #[DataProvider('relational')]
    public function testADryRunExecutesForRealThenCancelsEverything(string $envVar): void
    {
        $this->open($envVar);
        $table = $this->table('dry', true);

        $result = $this->script(\sprintf("INSERT INTO %1\$s VALUES (2, 'b'); DELETE FROM %1\$s WHERE id = 1;", $table), dryRun: true);

        self::assertSame(ScriptStatus::DryRun, $result->status);
        self::assertTrue($result->dryRun);
        self::assertSame(['rolled_back', 'rolled_back'], self::statuses($result));
        self::assertSame([1, 1], array_map(static fn ($s): ?int => $s->affectedRows, $result->steps), 'les lignes comptées sont celles de l\'exécution réelle');
        self::assertSame([1], $this->ids($table));
    }

    #[DataProvider('relational')]
    public function testADryRunThatFailsReportsTheFailureAndKeepsNothing(string $envVar): void
    {
        $this->open($envVar);
        $table = $this->table('dryfail', true);

        $result = $this->script(\sprintf("INSERT INTO %1\$s VALUES (1, 'dup');", $table), dryRun: true);

        self::assertSame(ScriptStatus::RolledBack, $result->status);
        self::assertSame(['failed'], self::statuses($result));
        self::assertSame([1], $this->ids($table));
    }

    public function testPostgreSqlCancelsDdlAndDataTogether(): void
    {
        $this->open('TARGET_POSTGRES_URL');
        $base = $this->table('base');
        $created = 'tdb_scr_new_'.bin2hex(random_bytes(3));
        $this->tables[] = $created;

        $failed = $this->script(\sprintf("CREATE TABLE %s (id int); INSERT INTO %s VALUES (1, 'a'); INSERT INTO %2\$s VALUES (1, 'dup');", $created, $base));
        self::assertSame(ScriptStatus::RolledBack, $failed->status);
        self::assertSame(['rolled_back', 'rolled_back', 'failed'], self::statuses($failed));
        self::assertSame([], $this->ids($base));
        self::assertSame(0, $this->scalar("SELECT count(*) FROM pg_tables WHERE tablename = '".$created."'"));
    }

    public function testPostgreSqlDryRunLeavesNoDdlBehind(): void
    {
        $this->open('TARGET_POSTGRES_URL');
        $created = 'tdb_scr_dry_'.bin2hex(random_bytes(3));
        $this->tables[] = $created;

        $result = $this->script(\sprintf('CREATE TABLE %1$s (id int); INSERT INTO %1$s VALUES (1);', $created), dryRun: true);

        self::assertSame(ScriptStatus::DryRun, $result->status);
        self::assertSame(0, $this->scalar("SELECT count(*) FROM pg_tables WHERE tablename = '".$created."'"));
    }

    #[DataProvider('mysqlFamily')]
    public function testMixingDataAndDdlOnMySqlKeepsWhatTheEngineCommittedImplicitly(string $envVar): void
    {
        $this->open($envVar);
        $table = $this->table('mix');
        $created = 'tdb_scr_mixnew_'.bin2hex(random_bytes(3));
        $this->tables[] = $created;

        $result = $this->script(\sprintf("INSERT INTO %1\$s VALUES (1, 'a'); CREATE TABLE %2\$s (id int); INSERT INTO %1\$s VALUES (2, 'b'); INSERT INTO %1\$s VALUES (1, 'dup'); INSERT INTO %1\$s VALUES (3, 'c');", $table, $created));

        self::assertSame(ScriptStatus::Partial, $result->status);
        self::assertSame(['applied', 'applied', 'rolled_back', 'failed', 'not_run'], self::statuses($result));
        self::assertSame([1], $this->ids($table), 'l\'insertion d\'avant le DDL est restée, celle d\'après a été annulée');
        self::assertSame(1, $this->scalar(\sprintf("SELECT count(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = '%s'", $created)));
    }

    #[DataProvider('mysqlFamily')]
    public function testAFailingDdlOnMySqlLeavesTheEarlierDdlApplied(string $envVar): void
    {
        $this->open($envVar);
        $table = $this->table('ddl');
        $created = 'tdb_scr_ddlnew_'.bin2hex(random_bytes(3));
        $this->tables[] = $created;

        $result = $this->script(\sprintf('CREATE TABLE %s (id int); CREATE TABLE %s (id int); CREATE TABLE %s (id int);', $created, $table, $created.'x'));

        self::assertSame(ScriptStatus::Partial, $result->status);
        self::assertSame(['applied', 'failed', 'not_run'], self::statuses($result));
    }

    public function testClickHouseStopsAtTheFirstErrorAndKeepsWhatPassed(): void
    {
        $this->open('TARGET_CLICKHOUSE_URL');
        $table = $this->table('ch');

        $result = $this->script(\sprintf("INSERT INTO %1\$s VALUES (1, 'a'); INSERT INTO %1\$s VALUES ('x', 'b'); INSERT INTO %1\$s VALUES (3, 'c');", $table));

        self::assertSame(ScriptStatus::Partial, $result->status);
        self::assertSame(['applied', 'failed', 'not_run'], self::statuses($result));
        self::assertSame([1], $this->ids($table));
    }

    public function testClickHouseNeverFakesADryRun(): void
    {
        $this->open('TARGET_CLICKHOUSE_URL');

        $this->expectException(ScriptDryRunUnavailableException::class);

        $this->script('SELECT 1', dryRun: true);
    }

    public function testClickHouseReportsAFirstStatementFailureAsFailed(): void
    {
        $this->open('TARGET_CLICKHOUSE_URL');

        $result = $this->script('SELECT * FROM table_that_does_not_exist_tdb');

        self::assertSame(ScriptStatus::Failed, $result->status);
        self::assertSame(['failed'], self::statuses($result));
    }

    #[DataProvider('relational')]
    public function testTheTimeoutBoundsTheWholeScript(string $envVar): void
    {
        $this->open($envVar);
        $table = $this->table('slow', true);
        $slow = 'SELECT sum(a.id + b.id + c.id) FROM order_items a CROSS JOIN order_items b CROSS JOIN order_items c';

        $started = microtime(true);
        $result = $this->script(\sprintf("INSERT INTO %s VALUES (2, 'b'); %s; INSERT INTO %1\$s VALUES (3, 'c');", $table, $slow), timeoutMs: 400);

        self::assertLessThan(8.0, microtime(true) - $started);
        self::assertSame('timeout', $result->failureReason);
        self::assertSame(ScriptStepStatus::Failed, $result->steps[1]->status);
        self::assertSame(ScriptStepStatus::NotRun, $result->steps[2]->status);
        self::assertSame([1], $this->ids($table));
    }

    /** Table MyISAM jetable (le moteur ne sait pas l'annuler). */
    private function myIsamTable(string $name): string
    {
        $table = 'tdb_scr_'.$name.'_'.bin2hex(random_bytes(3));
        $this->tables[] = $table;
        $this->raw?->executeStatement(\sprintf('CREATE TABLE %s (id int NOT NULL PRIMARY KEY, v varchar(20)) ENGINE=MyISAM', $table));

        return $table;
    }

    #[DataProvider('mysqlFamily')]
    public function testADryRunOnANonTransactionalTableIsRefusedBecauseTheRowWouldStay(string $envVar): void
    {
        $this->open($envVar);
        $table = $this->myIsamTable('dry');

        try {
            $this->script(\sprintf("INSERT INTO %s VALUES (1, 'a')", $table), dryRun: true);
            self::fail('A dry run on a MyISAM table cannot be honest.');
        } catch (ScriptDryRunUnavailableException $e) {
            self::assertSame('non_transactional_tables', $e->reason);
        }
        self::assertSame([], $this->ids($table));
    }

    #[DataProvider('mysqlFamily')]
    public function testAnErrorAfterAWriteToANonTransactionalTableReportsItAsKept(string $envVar): void
    {
        $this->open($envVar);
        $table = $this->myIsamTable('err');

        $result = $this->script(\sprintf("INSERT INTO %1\$s VALUES (1, 'a'); INSERT INTO %1\$s VALUES (1, 'dup'); INSERT INTO %1\$s VALUES (2, 'b');", $table));

        // La ligne MyISAM est restée : on ne dit pas « annulé » (le moteur peut aussi refuser lui-même la suite, GTID).
        self::assertSame(ScriptStatus::Partial, $result->status);
        self::assertSame(['applied', 'failed', 'not_run'], self::statuses($result));
        self::assertSame([1], $this->ids($table));
    }

    public function testPostgreSqlBoundsTheRowsOfABigSelectInsteadOfBufferingThemAll(): void
    {
        $this->open('TARGET_POSTGRES_URL');
        @file_put_contents('/proc/self/clear_refs', '5');
        $before = self::peakResidentKb();

        $result = $this->script('SELECT i, md5(i::text) FROM generate_series(1, 3000000) i');

        $grew = self::peakResidentKb() - $before;
        // 3 millions de lignes tamponnées coûtent environ 290 Mo hors de la mémoire PHP : la borne doit tenir sans.
        self::assertLessThan(60_000, $grew, 'The result must not be buffered in full.');
        self::assertSame(1000, $result->steps[0]->returnedRows);
        self::assertTrue($result->steps[0]->truncated);
    }

    private static function peakResidentKb(): int
    {
        $status = (string) @file_get_contents('/proc/self/status');

        return 1 === preg_match('/^VmHWM:\s+(\d+) kB/m', $status, $m) ? (int) $m[1] : 0;
    }
}
