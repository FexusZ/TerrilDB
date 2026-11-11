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
}
