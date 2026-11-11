<?php

declare(strict_types=1);

namespace App\Tests\Integration\Driver;

use App\Core\Connection\ConnectionParameters;
use App\Core\Connection\Engine;
use App\Core\Diagnostics\Availability;
use App\Core\Diagnostics\DigestOrder;
use App\Core\Diagnostics\DigestQuery;
use App\Core\Diagnostics\DigestReport;
use App\Core\Driver\Driver;
use App\Core\Driver\Exception\TargetConnectionFailedException;
use App\Core\Driver\TargetConnection;
use App\Driver\ClickHouse\ClickHouseDriver;
use App\Driver\DbalConnector;
use App\Driver\MySql\MySqlDriver;
use App\Driver\PostgreSql\PostgreSqlDriver;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\HttpClient;

/**
 * Les requêtes coûteuses par empreinte, lues sur les vrais moteurs : une requête repérable par un alias unique (le moteur
 * retire les commentaires de l'empreinte, pas les alias) est lancée, puis retrouvée avec ses appels, ses durées et son ratio.
 */
#[Group('integration')]
final class StatementDiagnosticsTest extends TestCase
{
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

    private function driver(Engine $engine): Driver
    {
        return match ($engine) {
            Engine::MySql, Engine::MariaDb => new MySqlDriver(new DbalConnector()),
            Engine::PostgreSql => new PostgreSqlDriver(new DbalConnector()),
            Engine::ClickHouse => new ClickHouseDriver(HttpClient::create()),
            Engine::Redis => throw new \LogicException('Redis has no driver here.'),
        };
    }

    private function open(string $envVar, ?ConnectionParameters $parameters = null): TargetConnection
    {
        $engine = TargetDatabase::engine($envVar);
        try {
            return $this->driver($engine)->connect($engine, $parameters ?? TargetDatabase::parameters($envVar));
        } catch (TargetConnectionFailedException) {
            self::markTestSkipped(\sprintf('%s is unreachable (docker compose --profile targets up -d --wait).', $envVar));
        }
    }

    #[DataProvider('mysqlFamily')]
    public function testAFullScanIsFoundByItsDigestWithItsRatio(string $envVar): void
    {
        $target = $this->open($envVar);
        $diagnostics = $target->diagnostics()->statements();
        if (Availability::Disabled === $diagnostics->status()->availability) {
            // MariaDB livre performance_schema éteint : « pas de conclusion », jamais une liste vide.
            self::assertSame(Engine::MariaDb, TargetDatabase::engine($envVar));
            self::assertSame('performance_schema_off', $diagnostics->status()->reason);
            self::assertSame([], $diagnostics->top(new DigestQuery())->digests);
            $target->close();

            return;
        }

        $alias = 'tdb75_'.bin2hex(random_bytes(6));
        $client = (new DbalConnector())->connect(TargetDatabase::engine($envVar), TargetDatabase::parameters($envVar));
        for ($i = 0; $i < 3; ++$i) {
            $client->fetchAllAssociative(\sprintf('SELECT COUNT(*), SLEEP(0.1) AS %s FROM order_items WHERE quantity > 1000', $alias));
        }
        $client->close();

        $report = $diagnostics->top(new DigestQuery(DigestOrder::TotalTime, 100));
        $digest = $this->find($report, $alias);

        self::assertSame(Availability::Available, $report->status->availability);
        self::assertSame(3, $digest->calls);
        self::assertSame('shop', $digest->database);
        self::assertStringContainsString('quantity', (string) $digest->text);
        self::assertStringNotContainsString('1000', (string) $digest->text, 'The digest never carries the values.');
        self::assertGreaterThanOrEqual(15000, $digest->rowsExamined, 'An unindexed filter reads the whole table, three times (the pause puts it among the costliest).');
        self::assertSame(3, $digest->rowsReturned);
        self::assertGreaterThanOrEqual(1000.0, $digest->ratio());
        self::assertSame(3, $digest->noIndexUsed);
        self::assertGreaterThan(0.0, $digest->totalMs);
        self::assertLessThan(60000.0, $digest->totalMs, 'Durations are milliseconds, not picoseconds.');
        self::assertEqualsWithDelta($digest->totalMs / 3, $digest->meanMs, 0.01);
        self::assertGreaterThanOrEqual($digest->meanMs, $digest->maxMs);
        self::assertNotNull($digest->firstSeen);
        self::assertNotNull($digest->lastSeen);
        $target->close();
    }

    #[DataProvider('mysqlFamily')]
    public function testTheLimitAndTheOrderAreHonoured(string $envVar): void
    {
        $target = $this->open($envVar);
        $diagnostics = $target->diagnostics()->statements();
        if (!$diagnostics->status()->isAvailable()) {
            self::markTestSkipped('performance_schema is off on this target.');
        }

        $byCalls = $diagnostics->top(new DigestQuery(DigestOrder::Calls, 5));
        $byTotal = $diagnostics->top(new DigestQuery(DigestOrder::TotalTime, 5));

        self::assertLessThanOrEqual(5, \count($byCalls->digests));
        self::assertSame(DigestOrder::Calls, $byCalls->order);
        $calls = array_map(static fn ($d): int => $d->calls, $byCalls->digests);
        $sorted = $calls;
        rsort($sorted);
        self::assertSame($sorted, $calls);
        $totals = array_map(static fn ($d): float => $d->totalMs, $byTotal->digests);
        $sorted = $totals;
        rsort($sorted);
        self::assertSame($sorted, $totals);
        $target->close();
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function mysqlOnly(): iterable
    {
        yield 'MySQL' => ['TARGET_MYSQL_URL'];
        yield 'MySQL 26' => ['TARGET_MYSQL26_URL'];
    }

    #[DataProvider('mysqlOnly')]
    public function testAnAccountWithoutTheRightGetsAStateNotAnError(string $envVar): void
    {
        $parameters = TargetDatabase::parameters($envVar);
        $user = 'tdb_stmt_'.bin2hex(random_bytes(4));
        $password = bin2hex(random_bytes(12));
        $admin = (new DbalConnector())->connect(Engine::MySql, $parameters);
        $admin->executeStatement(\sprintf("CREATE USER '%s'@'%%' IDENTIFIED BY '%s'", $user, $password));
        $admin->executeStatement(\sprintf("GRANT SELECT ON shop.* TO '%s'@'%%'", $user));
        $as = new ConnectionParameters($parameters->host, $parameters->port, $parameters->database, $user, $password);
        try {
            $reduced = $this->open($envVar, $as);
            $status = $reduced->diagnostics()->statements()->status();
            $report = $reduced->diagnostics()->statements()->top(new DigestQuery());
            $reduced->close();

            self::assertSame(Availability::PermissionDenied, $status->availability);
            self::assertSame('SELECT ON performance_schema', $status->privilege);
            self::assertSame(Availability::PermissionDenied, $report->status->availability);
            self::assertSame([], $report->digests);

            $admin->executeStatement(\sprintf("GRANT SELECT ON performance_schema.* TO '%s'@'%%'", $user));
            $granted = $this->open($envVar, $as);
            self::assertSame(Availability::Available, $granted->diagnostics()->statements()->status()->availability);
            $granted->close();
        } finally {
            $admin->executeStatement(\sprintf("DROP USER IF EXISTS '%s'@'%%'", $user));
            $admin->close();
        }
    }

    private function find(DigestReport $report, string $alias): \App\Core\Diagnostics\StatementDigest
    {
        foreach ($report->digests as $digest) {
            if (str_contains((string) $digest->text, $alias)) {
                return $digest;
            }
        }
        self::fail('The digest of the query is not in the top list.');
    }
}
