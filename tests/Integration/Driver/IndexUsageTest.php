<?php

declare(strict_types=1);

namespace App\Tests\Integration\Driver;

use App\Core\Connection\ConnectionParameters;
use App\Core\Driver\Exception\TargetConnectionFailedException;
use App\Core\Schema\IndexUsageReport;
use App\Driver\ClickHouse\ClickHouseHttp;
use App\Driver\ClickHouse\ClickHouseIntrospector;
use App\Driver\DbalConnector;
use App\Driver\MySql\MySqlIntrospector;
use App\Driver\PostgreSql\PostgreSqlIntrospector;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\HttpClient;

/**
 * L'usage des index lu aux compteurs du moteur, sur une table à part (le schéma partagé n'est jamais touché).
 */
#[Group('integration')]
final class IndexUsageTest extends TestCase
{
    private function connect(string $envVar, ?ConnectionParameters $as = null): Connection
    {
        try {
            return (new DbalConnector())->connect(TargetDatabase::engine($envVar), $as ?? TargetDatabase::parameters($envVar));
        } catch (TargetConnectionFailedException) {
            self::markTestSkipped(\sprintf('%s is unreachable (docker compose --profile targets up -d --wait).', $envVar));
        }
    }

    /**
     * @return array<string, int> lectures par index de la table de test
     */
    private static function reads(IndexUsageReport $report): array
    {
        $reads = [];
        foreach ($report->usages as $usage) {
            if ('usage_probe' === $usage->table) {
                $reads[$usage->index] = $usage->reads;
            }
        }

        return $reads;
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function mysqlTargets(): iterable
    {
        yield 'MySQL' => ['TARGET_MYSQL_URL'];
        yield 'MySQL 26' => ['TARGET_MYSQL26_URL'];
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function mariaDbTargets(): iterable
    {
        yield 'MariaDB' => ['TARGET_MARIADB_URL'];
        yield 'MariaDB 13' => ['TARGET_MARIADB13_URL'];
    }

    #[DataProvider('mysqlTargets')]
    public function testMySqlCountsTheReadsOfEachIndexAndGivesTheObservedDuration(string $envVar): void
    {
        $connection = $this->connect($envVar);
        $introspector = new MySqlIntrospector($connection);

        try {
            $connection->executeStatement('DROP TABLE IF EXISTS usage_probe');
            $connection->executeStatement('CREATE TABLE usage_probe (id INT NOT NULL PRIMARY KEY, a INT NOT NULL, b INT NOT NULL, KEY idx_a (a), KEY idx_b (b))');
            $connection->executeStatement('INSERT INTO usage_probe VALUES (1, 1, 1), (2, 2, 2), (3, 3, 3)');
            $before = self::reads($introspector->indexUsage());
            $connection->fetchAllAssociative('SELECT id FROM usage_probe FORCE INDEX (idx_b) WHERE b = 2');
            $report = $introspector->indexUsage();
        } finally {
            $connection->executeStatement('DROP TABLE IF EXISTS usage_probe');
            $connection->close();
        }

        self::assertTrue($report->available, (string) $report->reason);
        self::assertGreaterThan(0, $report->observedSeconds);
        $reads = self::reads($report);
        // Les écritures qui entretiennent un index ne sont pas des lectures.
        self::assertSame(0, $before['idx_a'] ?? null);
        self::assertSame(0, $reads['idx_a'] ?? null);
        self::assertGreaterThan(0, $reads['idx_b'] ?? 0);
    }

    #[DataProvider('mariaDbTargets')]
    public function testMariaDbWithoutItsPerformanceSchemaSaysSoInsteadOfGuessing(string $envVar): void
    {
        $connection = $this->connect($envVar);

        try {
            $report = (new MySqlIntrospector($connection))->indexUsage();
        } finally {
            $connection->close();
        }

        self::assertFalse($report->available);
        self::assertSame('performance_schema_off', $report->reason);
        self::assertSame([], $report->usages);
    }

    #[DataProvider('mysqlTargets')]
    public function testAnAccountWithoutRightOnPerformanceSchemaIsAnUnavailableStateNotAnError(string $envVar): void
    {
        $admin = $this->connect($envVar);
        $base = TargetDatabase::parameters($envVar);
        $password = bin2hex(random_bytes(12));

        try {
            $admin->executeStatement('DROP USER IF EXISTS usage_probe_user');
            $admin->executeStatement('CREATE USER usage_probe_user IDENTIFIED BY '.$admin->quote($password));
            $admin->executeStatement('GRANT SELECT ON `'.str_replace('`', '``', $base->database).'`.* TO usage_probe_user');
            $limited = $this->connect($envVar, new ConnectionParameters($base->host, $base->port, $base->database, 'usage_probe_user', $password));
            try {
                $report = (new MySqlIntrospector($limited))->indexUsage();
            } finally {
                $limited->close();
            }
        } finally {
            $admin->executeStatement('DROP USER IF EXISTS usage_probe_user');
            $admin->close();
        }

        self::assertFalse($report->available);
        self::assertSame('permission_denied', $report->reason);
    }

    public function testPostgreSqlCountsIndexScansSinceTheStatisticsStarted(): void
    {
        $connection = $this->connect('TARGET_POSTGRES_URL');
        $introspector = new PostgreSqlIntrospector($connection);

        try {
            $connection->executeStatement('DROP TABLE IF EXISTS usage_probe');
            $connection->executeStatement('CREATE TABLE usage_probe (id int PRIMARY KEY, a int NOT NULL, b int NOT NULL)');
            $connection->executeStatement('CREATE INDEX idx_a ON usage_probe (a)');
            $connection->executeStatement('CREATE INDEX idx_b ON usage_probe (b)');
            $connection->executeStatement('INSERT INTO usage_probe SELECT n, n, n FROM generate_series(1, 2000) n');
            $connection->executeStatement('ANALYZE usage_probe');
            $connection->executeStatement('SET enable_seqscan = off');
            $connection->fetchAllAssociative('SELECT id FROM usage_probe WHERE b = 7');
            // Les compteurs d'un autre processus se publient avec un léger retard.
            $reads = [];
            for ($try = 0; $try < 20; ++$try) {
                $report = $introspector->indexUsage();
                $reads = self::reads($report);
                if (($reads['idx_b'] ?? 0) > 0) {
                    break;
                }
                usleep(300000);
            }
        } finally {
            $connection->executeStatement('DROP TABLE IF EXISTS usage_probe');
            $connection->close();
        }

        self::assertTrue($report->available);
        self::assertGreaterThan(0, $report->observedSeconds);
        self::assertSame(0, $reads['idx_a'] ?? null);
        self::assertGreaterThan(0, $reads['idx_b'] ?? 0);
        self::assertArrayHasKey('usage_probe_pkey', $reads);
    }

    public function testClickHouseHasNoIndexCounters(): void
    {
        $parameters = TargetDatabase::parameters('TARGET_CLICKHOUSE_URL');
        $report = (new ClickHouseIntrospector(new ClickHouseHttp(HttpClient::create(), $parameters), $parameters->database))->indexUsage();

        self::assertFalse($report->available);
        self::assertSame('not_supported', $report->reason);
    }
}
