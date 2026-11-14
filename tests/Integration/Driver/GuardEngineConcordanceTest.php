<?php

declare(strict_types=1);

namespace App\Tests\Integration\Driver;

use App\Core\Connection\Engine;
use App\Core\Driver\Exception\TargetConnectionFailedException;
use App\Core\Guard\Decision;
use App\Core\Guard\StatementGuard;
use App\Core\Guard\StatementKind;
use App\Core\Query\Exception\QueryFailedException;
use App\Core\Query\QueryMode;
use App\Core\Query\QueryOptions;
use App\Core\Query\WritePermissions;
use App\Driver\DbalConnector;
use App\Driver\MySql\MySqlQueryRunner;
use App\Driver\PostgreSql\PostgreSqlQueryRunner;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Filtre et moteur sur les fonctions à effet de bord : ce que le moteur refuse déjà en lecture seule, et ce qu'il laisse
 * passer et que le garde doit donc refuser seul (verrous durables), ou laisser passer en comptant sur le délai (pauses).
 */
#[Group('integration')]
final class GuardEngineConcordanceTest extends TestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function mysqlTargets(): iterable
    {
        yield 'MySQL' => ['TARGET_MYSQL_URL'];
        yield 'MySQL 26' => ['TARGET_MYSQL26_URL'];
        yield 'MariaDB' => ['TARGET_MARIADB_URL'];
        yield 'MariaDB 13' => ['TARGET_MARIADB13_URL'];
    }

    private function mysql(string $envVar): MySqlQueryRunner
    {
        $engine = TargetDatabase::engine($envVar);
        try {
            $connection = (new DbalConnector())->connect($engine, TargetDatabase::parameters($envVar));
        } catch (TargetConnectionFailedException) {
            self::markTestSkipped($envVar.' is unreachable (docker compose --profile targets up -d --wait).');
        }

        return new MySqlQueryRunner($connection, $engine);
    }

    private function postgres(): PostgreSqlQueryRunner
    {
        try {
            $connection = (new DbalConnector())->connect(TargetDatabase::engine('TARGET_POSTGRES_URL'), TargetDatabase::parameters('TARGET_POSTGRES_URL'));
        } catch (TargetConnectionFailedException) {
            self::markTestSkipped('The PostgreSQL target is unreachable (docker compose --profile targets up -d --wait).');
        }

        return new PostgreSqlQueryRunner($connection);
    }

    private function readOnly(int $timeoutMs = 30_000): QueryOptions
    {
        return new QueryOptions(QueryMode::ReadOnly, $timeoutMs, 10);
    }

    #[DataProvider('mysqlTargets')]
    public function testMySqlLetsAdvisoryLocksThroughSoOnlyTheGuardRefusesThem(string $envVar): void
    {
        $runner = $this->mysql($envVar);

        $verdict = (new StatementGuard())->check("select get_lock('guard_corpus', 0)", TargetDatabase::engine($envVar), QueryMode::ReadOnly);
        self::assertSame(Decision::Refused, $verdict->decision);

        // Le moteur, lui, ne s'y oppose pas en lecture seule : le filtre est ici la seule barrière.
        self::assertSame([[1]], $runner->run("select get_lock('guard_corpus', 0)", StatementKind::Read, $this->readOnly())->rows);
    }

    #[DataProvider('mysqlTargets')]
    public function testMySqlPausesAreCutShortByTheDelay(string $envVar): void
    {
        $runner = $this->mysql($envVar);
        $started = microtime(true);

        try {
            $runner->run('select sleep(20)', StatementKind::Read, $this->readOnly(300));
        } catch (\Throwable) {
            // un délai dépassé est une erreur ou un résultat écourté selon le moteur : seule la durée compte ici
        }

        self::assertLessThan(10.0, microtime(true) - $started);
    }

    public function testPostgreSqlRefusesSequencesInReadOnlyLikeTheGuard(): void
    {
        $runner = $this->postgres();

        self::assertSame(Decision::Refused, (new StatementGuard())->check("select nextval('categories_id_seq')", Engine::PostgreSql, QueryMode::ReadOnly)->decision);
        $this->expectException(QueryFailedException::class);
        $runner->run("select nextval('categories_id_seq')", StatementKind::Read, $this->readOnly());
    }

    public function testPostgreSqlLetsAdvisoryLocksThroughSoOnlyTheGuardRefusesThem(): void
    {
        $runner = $this->postgres();

        self::assertSame(Decision::Refused, (new StatementGuard())->check('select pg_advisory_lock(7)', Engine::PostgreSql, QueryMode::ReadOnly)->decision);
        self::assertSame(1, $runner->run('select pg_advisory_lock(7), 1', StatementKind::Read, $this->readOnly())->rowCount());
    }

    public function testPostgreSqlCallsAFunctionWrittenBetweenQuotesSoTheGuardMustRefuseIt(): void
    {
        $runner = $this->postgres();
        $guard = new StatementGuard();
        $write = new QueryOptions(QueryMode::ReadWrite, 30_000, 10);

        foreach (['select "nextval"(\'categories_id_seq\')', 'select "pg_advisory_lock"(7)'] as $sql) {
            self::assertSame(Decision::Refused, $guard->check($sql, Engine::PostgreSql, QueryMode::ReadOnly)->decision, $sql);
        }
        // Le moteur appelle bien la fonction : la séquence avance, c'est donc une écriture.
        $first = $runner->run('select "nextval"(\'categories_id_seq\') as v', StatementKind::Write, $write)->rows[0][0];
        $second = $runner->run('select "nextval"(\'categories_id_seq\') as v', StatementKind::Write, $write)->rows[0][0];
        self::assertGreaterThan((int) $first, (int) $second);
        // Même verdict du moteur en lecture seule : refus d'écrire, pas « fonction inconnue ».
        $this->expectException(QueryFailedException::class);
        $runner->run('select "nextval"(\'categories_id_seq\')', StatementKind::Read, $this->readOnly());
    }

    #[DataProvider('mysqlTargets')]
    public function testMySqlCallsAFunctionWrittenBetweenBackticksSoTheGuardMustRefuseIt(string $envVar): void
    {
        $runner = $this->mysql($envVar);

        self::assertSame(Decision::Refused, (new StatementGuard())->check("select `get_lock`('a', 1)", TargetDatabase::engine($envVar), QueryMode::ReadOnly)->decision);
        self::assertSame(1, $runner->run("select `get_lock`('a', 1), `release_lock`('a')", StatementKind::Read, $this->readOnly())->rowCount());
    }

    public function testPostgreSqlCreatesATableFromSelectIntoAndExplainAnalyzeSoADataOnlyGrantMustBeRefused(): void
    {
        $runner = $this->postgres();
        $guard = new StatementGuard();
        $dataOnly = new WritePermissions(true, false);
        $write = new QueryOptions(QueryMode::ReadWrite, 30_000, 10);
        $statements = [
            'guard_into_a' => 'select * into guard_into_a from categories',
            'guard_into_b' => 'explain analyze create table guard_into_b as select 1 as a',
            'guard_into_c' => 'explain (analyze, format json) select * into guard_into_c from categories',
            'guard_into_d' => 'with x as (select 1 as a) select * into guard_into_d from x',
        ];

        try {
            foreach ($statements as $table => $sql) {
                $verdict = $guard->check($sql, Engine::PostgreSql, QueryMode::ReadWrite, $dataOnly);
                self::assertSame(Decision::Refused, $verdict->decision, $sql);
                self::assertSame(StatementKind::Schema, $verdict->kind, $sql);
                // Avec le droit sur la structure, elle est jugée pour ce qu'elle est : une instruction de structure.
                self::assertSame(StatementKind::Schema, $guard->check($sql, Engine::PostgreSql, QueryMode::ReadWrite)->kind, $sql);

                // Le moteur, lui, la crée bel et bien : c'est donc au garde de la refuser.
                $runner->run($sql, StatementKind::Write, $write);
                self::assertSame(1, $runner->run(\sprintf("select 1 from pg_tables where tablename = '%s'", $table), StatementKind::Read, $this->readOnly())->rowCount(), $sql);
            }
        } finally {
            foreach (array_keys($statements) as $table) {
                $runner->run('drop table if exists '.$table, StatementKind::Write, $write);
            }
        }
    }
}
