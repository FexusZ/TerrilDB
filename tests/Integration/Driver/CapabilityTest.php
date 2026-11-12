<?php

declare(strict_types=1);

namespace App\Tests\Integration\Driver;

use App\Core\Connection\Engine;
use App\Core\Driver\Driver;
use App\Core\Driver\EngineCapability;
use App\Core\Driver\Exception\TargetConnectionFailedException;
use App\Core\Driver\ServerFlavour;
use App\Core\Driver\TargetConnection;
use App\Driver\ClickHouse\ClickHouseDriver;
use App\Driver\ClickHouse\ClickHouseException;
use App\Driver\ClickHouse\ClickHouseHttp;
use App\Driver\DbalConnector;
use App\Driver\MySql\MySqlDriver;
use App\Driver\PostgreSql\PostgreSqlDriver;
use App\Driver\Redis\RedisConnector;
use App\Driver\Redis\RedisDriver;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\HttpClient;

/**
 * Chaque capacité déclarée par la matrice est rejouée contre les vraies cibles par une requête minimale, et une capacité
 * non déclarée doit échouer : la table ne peut pas dériver quand une série change. Les objets d'essai (tables, séquences)
 * portent un nom aléatoire et sont retirés dans tous les cas ; les verrous sont pris sur des tables temporaires.
 */
#[Group('integration')]
final class CapabilityTest extends TestCase
{
    private ?TargetConnection $target = null;
    private ?Connection $raw = null;
    private ?ClickHouseHttp $http = null;

    /** @var list<string> */
    private array $teardown = [];

    protected function tearDown(): void
    {
        foreach ($this->teardown as $sql) {
            try {
                $this->execute($sql);
            } catch (\Throwable) {
            }
        }
        $this->teardown = [];
        $this->raw?->close();
        $this->target?->close();
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function targets(): iterable
    {
        yield from TargetDatabase::all();
    }

    /**
     * @return iterable<string, array{string, EngineCapability}>
     */
    public static function everyCapabilityOnEveryTarget(): iterable
    {
        foreach (self::targets() as $label => [$env]) {
            foreach (EngineCapability::cases() as $capability) {
                yield $label.' / '.$capability->value => [$env, $capability];
            }
        }
    }

    private function driver(Engine $engine): Driver
    {
        return match ($engine) {
            Engine::MySql, Engine::MariaDb => new MySqlDriver(new DbalConnector()),
            Engine::PostgreSql => new PostgreSqlDriver(new DbalConnector()),
            Engine::ClickHouse => new ClickHouseDriver(HttpClient::create()),
            Engine::Redis => new RedisDriver(new RedisConnector()),
        };
    }

    private function open(string $env): void
    {
        $engine = TargetDatabase::engine($env);
        $parameters = TargetDatabase::parameters($env);
        try {
            $this->target = $this->driver($engine)->connect($engine, $parameters);
        } catch (TargetConnectionFailedException) {
            self::markTestSkipped('The target is unreachable (docker compose --profile targets up -d --wait).');
        }
        if (Engine::ClickHouse === $engine) {
            $this->http = new ClickHouseHttp(HttpClient::create(), $parameters);
        } else {
            $this->raw = (new DbalConnector())->connect($engine, $parameters);
        }
    }

    private function execute(string $sql): void
    {
        if (null !== $this->http) {
            $this->http->query($sql);

            return;
        }
        \assert(null !== $this->raw);
        $this->raw->executeQuery($sql)->fetchAllNumeric();
    }

    /**
     * Les instructions d'une capacité : mise en place, la sonde (échec attendu ou non), nettoyage. `{t}` = un nom d'objet jetable.
     *
     * @return array{list<string>, string, bool, list<string>}
     */
    private static function probe(Engine $engine, EngineCapability $capability): array
    {
        $mysql = Engine::MySql === $engine;
        $mariadb = Engine::MariaDb === $engine;
        $clickhouse = Engine::ClickHouse === $engine;
        $temp = $clickhouse ? 'CREATE TABLE {t} (a Int32) ENGINE = MergeTree ORDER BY a' : 'CREATE TEMPORARY TABLE {t} (a INT)';
        $drop = 'DROP TABLE IF EXISTS {t}';

        return match ($capability) {
            EngineCapability::RecursiveCte => [[], 'WITH RECURSIVE n(i) AS (SELECT 1 UNION ALL SELECT i + 1 FROM n WHERE i < 3) SELECT COUNT(*) FROM n', false, []],
            EngineCapability::Lateral => [[], 'SELECT l.b FROM (SELECT 1 AS a) t JOIN LATERAL (SELECT t.a AS b) l ON TRUE', false, []],
            EngineCapability::WindowFunctions => [[], 'SELECT SUM(a) OVER (ORDER BY a) FROM (SELECT 1 AS a) t', false, []],
            EngineCapability::JsonTable => [[], "SELECT v FROM JSON_TABLE('[1, 2]', '\$[*]' COLUMNS (v INT PATH '\$')) j", false, []],
            EngineCapability::SystemVersioning => [['CREATE TABLE {t} (a INT) WITH SYSTEM VERSIONING'], 'SELECT a FROM {t} FOR SYSTEM_TIME ALL', false, [$drop]],
            EngineCapability::SkipLocked => [[$temp], 'SELECT a FROM {t} FOR UPDATE SKIP LOCKED', false, [$drop]],
            EngineCapability::NoWait => [[$temp], 'SELECT a FROM {t} FOR UPDATE NOWAIT', false, [$drop]],
            EngineCapability::InvisibleIndexes => [[], $mariadb ? 'CREATE TEMPORARY TABLE {t} (a INT, INDEX i (a) IGNORED)' : 'CREATE TEMPORARY TABLE {t} (a INT, INDEX i (a) INVISIBLE)', false, [$drop]],
            EngineCapability::ActualPlan => [[], $mariadb ? 'ANALYZE SELECT 1' : 'EXPLAIN ANALYZE SELECT 1', false, []],
            EngineCapability::GeneratedColumns => [[], $clickhouse ? 'CREATE TABLE {t} (a Int32, b Int32 ALIAS a + 1) ENGINE = MergeTree ORDER BY a' : 'CREATE TEMPORARY TABLE {t} (a INT, b INT GENERATED ALWAYS AS (a + 1) STORED)', false, [$drop]],
            EngineCapability::Sequences => [[], 'CREATE SEQUENCE {t}', false, ['DROP SEQUENCE IF EXISTS {t}']],
            EngineCapability::EnforcedCheck => [[$clickhouse ? 'CREATE TABLE {t} (a Int32, CONSTRAINT positive CHECK a > 0) ENGINE = MergeTree ORDER BY a' : 'CREATE TEMPORARY TABLE {t} (a INT, CHECK (a > 0))'], 'INSERT INTO {t} (a) VALUES (0)', true, [$drop]],
        };
    }

    /**
     * Vrai quand la fonction marche sur la cible (la sonde réussit, ou échoue si on s'y attend).
     */
    private function works(Engine $engine, EngineCapability $capability): bool
    {
        [$setup, $probe, $inverted, $teardown] = self::probe($engine, $capability);
        $name = 'tdb_cap_'.bin2hex(random_bytes(4));
        foreach ($teardown as $sql) {
            $this->teardown[] = str_replace('{t}', $name, $sql);
        }
        try {
            foreach ($setup as $sql) {
                $this->execute(str_replace('{t}', $name, $sql));
            }
        } catch (\Throwable) {
            // La mise en place est elle-même la fonction à essayer (ex. WITH SYSTEM VERSIONING) : elle échoue là où elle n'existe pas.
            return false;
        }
        try {
            $this->execute(str_replace('{t}', $name, $probe));
            $succeeded = true;
        } catch (\Throwable) {
            $succeeded = false;
        }

        return $inverted ? !$succeeded : $succeeded;
    }

    #[DataProvider('everyCapabilityOnEveryTarget')]
    public function testTheMatrixSaysWhatTheTargetReallyDoes(string $env, EngineCapability $capability): void
    {
        $this->open($env);
        \assert(null !== $this->target);

        self::assertSame(
            $this->target->capabilities()->supports($capability),
            $this->works(TargetDatabase::engine($env), $capability),
            \sprintf('The matrix and %s disagree about "%s".', $env, $capability->value),
        );
    }

    /**
     * @return iterable<string, array{string, string, ServerFlavour}>
     */
    public static function versions(): iterable
    {
        yield 'MySQL' => ['TARGET_MYSQL_URL', '8.4', ServerFlavour::Standard];
        yield 'MySQL 26' => ['TARGET_MYSQL26_URL', '26.', ServerFlavour::Standard];
        yield 'MariaDB' => ['TARGET_MARIADB_URL', '11.8', ServerFlavour::MariaDb];
        yield 'MariaDB 13' => ['TARGET_MARIADB13_URL', '13.', ServerFlavour::MariaDb];
        yield 'PostgreSQL' => ['TARGET_POSTGRES_URL', '18.', ServerFlavour::Standard];
        yield 'ClickHouse' => ['TARGET_CLICKHOUSE_URL', '26.10', ServerFlavour::Standard];
    }

    /**
     * @param non-empty-string $series
     */
    #[DataProvider('versions')]
    public function testTheVersionIsReadWhenTheConnectionOpens(string $env, string $series, ServerFlavour $flavour): void
    {
        $this->open($env);
        \assert(null !== $this->target);

        $version = $this->target->version();
        self::assertNotNull($version);
        self::assertStringStartsWith($series, $version->major.'.'.$version->minor.'.');
        self::assertSame($flavour, $version->flavour);
    }

    public function testRedisReadsItsVersionAndClaimsNoSqlCapability(): void
    {
        $engine = Engine::Redis;
        $parameters = TargetDatabase::parameters(RedisTarget::ENV);
        try {
            $this->target = $this->driver($engine)->connect($engine, $parameters);
        } catch (TargetConnectionFailedException) {
            self::markTestSkipped('The target is unreachable (docker compose --profile targets up -d --wait).');
        }

        self::assertGreaterThanOrEqual(7, $this->target->version()?->major);
        self::assertSame([], $this->target->capabilities()->supported());
    }

    public function testClickHouseRejectsWhatItDoesNotDeclare(): void
    {
        $this->open('TARGET_CLICKHOUSE_URL');
        \assert(null !== $this->http);

        $this->expectException(ClickHouseException::class);
        $this->http->query("SELECT v FROM JSON_TABLE('[1]', '\$[*]' COLUMNS (v INT PATH '\$')) j");
    }
}
