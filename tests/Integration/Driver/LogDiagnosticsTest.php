<?php

declare(strict_types=1);

namespace App\Tests\Integration\Driver;

use App\Core\Connection\ConnectionParameters;
use App\Core\Connection\Engine;
use App\Core\Diagnostics\Availability;
use App\Core\Diagnostics\Log\LogMessage;
use App\Core\Driver\Exception\TargetConnectionFailedException;
use App\Core\Driver\TargetConnection;
use App\Driver\DbalConnector;
use App\Driver\MySql\MySqlDriver;
use App\Driver\PostgreSql\PostgreSqlDriver;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Les journaux du serveur lus EN SQL sur les vrais moteurs : journal d'erreurs de MySQL, journal des requêtes lentes en table
 * (MySQL et MariaDB), état explicite ailleurs, compte aux droits réduits (créé puis supprimé). Les réglages du journal des
 * requêtes lentes sont modifiés par le test puis remis comme ils étaient.
 */
#[Group('integration')]
final class LogDiagnosticsTest extends TestCase
{
    /** @var array<string, string> variables du serveur d'avant le test, remises en place par tearDown */
    private array $saved = [];
    private ?string $savedEnvVar = null;
    private ?string $user = null;
    private ?string $userEnvVar = null;

    protected function tearDown(): void
    {
        if (null !== $this->savedEnvVar) {
            $admin = $this->admin($this->savedEnvVar);
            foreach ($this->saved as $name => $value) {
                $admin->executeStatement(\sprintf('SET GLOBAL %s = %s', $name, $admin->quote($value)));
            }
            $admin->executeStatement('TRUNCATE TABLE mysql.slow_log');
        }
        if (null !== $this->user && null !== $this->userEnvVar) {
            $this->admin($this->userEnvVar)->executeStatement(\sprintf("DROP USER IF EXISTS '%s'@'%%'", $this->user));
        }
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

    /**
     * @return iterable<string, array{string}>
     */
    public static function slowLogTargets(): iterable
    {
        yield from self::mysqlTargets();
        yield from self::mariaDbTargets();
    }

    private function open(string $envVar, ?ConnectionParameters $parameters = null): TargetConnection
    {
        $engine = TargetDatabase::engine($envVar);
        $driver = Engine::PostgreSql === $engine ? new PostgreSqlDriver(new DbalConnector()) : new MySqlDriver(new DbalConnector());
        try {
            return $driver->connect($engine, $parameters ?? TargetDatabase::parameters($envVar));
        } catch (TargetConnectionFailedException) {
            self::markTestSkipped(\sprintf('%s is unreachable (docker compose --profile targets up -d --wait).', $envVar));
        }
    }

    private function admin(string $envVar): Connection
    {
        return (new DbalConnector())->connect(TargetDatabase::engine($envVar), TargetDatabase::parameters($envVar));
    }

    /**
     * @param array<string, string> $values
     */
    private function setServer(string $envVar, array $values): void
    {
        $admin = $this->admin($envVar);
        $this->savedEnvVar = $envVar;
        foreach ($values as $name => $value) {
            $this->saved[$name] ??= (string) $admin->fetchOne('SELECT @@GLOBAL.'.$name);
            $admin->executeStatement(\sprintf('SET GLOBAL %s = %s', $name, $admin->quote($value)));
        }
        $admin->executeStatement('TRUNCATE TABLE mysql.slow_log');
    }

    #[DataProvider('mysqlTargets')]
    public function testTheErrorLogOfMySqlIsReadInSqlWithAtLeastTheStartupLines(string $envVar): void
    {
        $target = $this->open($envVar);

        $report = $target->diagnostics()->logs()->errorLog();
        $target->close();

        self::assertSame(Availability::Available, $report->status->availability);
        self::assertNotSame([], $report->entries);
        self::assertLessThanOrEqual(200, \count($report->entries));
        $previous = null;
        foreach ($report->entries as $entry) {
            self::assertContains($entry->level, ['system', 'error', 'warning', 'note']);
            self::assertLessThanOrEqual(LogMessage::MAX_LENGTH, mb_strlen($entry->message ?? ''));
            self::assertNotNull($entry->loggedAt);
            if (null !== $previous) {
                self::assertLessThanOrEqual($previous, $entry->loggedAt, 'The most recent line comes first.');
            }
            $previous = $entry->loggedAt;
        }
        self::assertContains('Server', array_map(static fn ($e): ?string => $e->subsystem, $report->entries));
    }

    #[DataProvider('mariaDbTargets')]
    public function testMariaDbHasNoSqlErrorLog(string $envVar): void
    {
        $target = $this->open($envVar);

        $report = $target->diagnostics()->logs()->errorLog();
        $target->close();

        self::assertSame(Availability::Unsupported, $report->status->availability);
        self::assertSame('log_is_a_file', $report->status->reason);
        self::assertSame([], $report->entries);
    }

    public function testPostgreSqlHasNeitherLogInSql(): void
    {
        $target = $this->open('TARGET_POSTGRES_URL');

        $logs = $target->diagnostics()->logs();
        $error = $logs->errorLog();
        $slow = $logs->slowQueries();
        $capability = $target->diagnostics()->capabilities()['logs'];
        $target->close();

        self::assertSame(Availability::Unsupported, $error->status->availability);
        self::assertSame('log_is_a_file', $error->status->reason);
        self::assertSame(Availability::Unsupported, $slow->status->availability);
        self::assertSame('log_is_a_file', $slow->status->reason);
        self::assertSame(Availability::Unsupported, $capability->availability);
    }

    #[DataProvider('slowLogTargets')]
    public function testTheSlowLogIsDisabledWithTheReasonWhileItGoesToAFile(string $envVar): void
    {
        $this->setServer($envVar, ['log_output' => 'FILE', 'slow_query_log' => '1']);
        $target = $this->open($envVar);

        $report = $target->diagnostics()->logs()->slowQueries();
        $target->close();

        self::assertSame(Availability::Disabled, $report->status->availability);
        self::assertSame('log_output_without_table', $report->status->reason);
        self::assertSame([], $report->queries);
    }

    #[DataProvider('slowLogTargets')]
    public function testTheSlowLogIsDisabledWithTheReasonWhileItIsOff(string $envVar): void
    {
        $this->setServer($envVar, ['log_output' => 'TABLE', 'slow_query_log' => '0']);
        $target = $this->open($envVar);

        $report = $target->diagnostics()->logs()->slowQueries();
        $target->close();

        self::assertSame(Availability::Disabled, $report->status->availability);
        self::assertSame('slow_query_log_off', $report->status->reason);
    }

    #[DataProvider('slowLogTargets')]
    public function testASlowQueryIsReadBackMaskedFromTheTable(string $envVar): void
    {
        $this->setServer($envVar, ['log_output' => 'TABLE', 'slow_query_log' => '1', 'long_query_time' => '0.1']);
        $target = $this->open($envVar);
        $load = $this->admin($envVar);
        $load->executeQuery("SELECT SLEEP(0.4), 'tdb_secret_value_91', 424242")->fetchAllAssociative();

        $report = $target->diagnostics()->logs()->slowQueries();
        $capability = $target->diagnostics()->capabilities()['logs'];
        $target->close();

        self::assertSame(Availability::Available, $report->status->availability);
        self::assertEqualsWithDelta(0.1, $report->thresholdSeconds, 0.001);
        self::assertSame(Availability::Available, $capability->availability);
        $found = null;
        foreach ($report->queries as $query) {
            if (str_contains($query->query ?? '', 'SLEEP(?)')) {
                $found = $query;
            }
        }
        self::assertNotNull($found, 'The slow SELECT SLEEP is in the log.');
        self::assertStringNotContainsString('tdb_secret_value_91', $found->query ?? '');
        self::assertStringNotContainsString('424242', $found->query ?? '');
        self::assertGreaterThanOrEqual(300.0, $found->durationMs);
        self::assertNotNull($found->startedAt);
        self::assertNotNull($found->user);
        self::assertStringNotContainsString('@', $found->user);
        self::assertStringNotContainsString('[', $found->user);
    }

    #[DataProvider('slowLogTargets')]
    public function testTheSlowLogIsBoundedAndSaysSo(string $envVar): void
    {
        $this->setServer($envVar, ['log_output' => 'TABLE', 'slow_query_log' => '1', 'long_query_time' => '0']);
        $load = $this->admin($envVar);
        for ($i = 0; $i < 205; ++$i) {
            $load->fetchOne('SELECT 1');
        }
        $target = $this->open($envVar);

        $report = $target->diagnostics()->logs()->slowQueries();
        $target->close();

        self::assertCount(200, $report->queries);
        self::assertTrue($report->truncated);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function reduced(): iterable
    {
        yield 'MySQL' => ['TARGET_MYSQL_URL', 'SELECT ON performance_schema'];
        yield 'MySQL 26' => ['TARGET_MYSQL26_URL', 'SELECT ON performance_schema'];
    }

    #[DataProvider('reduced')]
    public function testAnAccountWithoutTheRightGetsStatesNotErrors(string $envVar, string $privilege): void
    {
        $this->setServer($envVar, ['log_output' => 'TABLE', 'slow_query_log' => '1']);
        $user = 'tdb_logs_'.bin2hex(random_bytes(4));
        $password = bin2hex(random_bytes(12));
        $admin = $this->admin($envVar);
        $this->user = $user;
        $this->userEnvVar = $envVar;
        $admin->executeStatement(\sprintf("CREATE USER '%s'@'%%' IDENTIFIED BY '%s'", $user, $password));
        $admin->executeStatement(\sprintf("GRANT SELECT ON shop.* TO '%s'@'%%'", $user));
        $parameters = TargetDatabase::parameters($envVar);

        $target = $this->open($envVar, new ConnectionParameters($parameters->host, $parameters->port, $parameters->database, $user, $password));
        $error = $target->diagnostics()->logs()->errorLog();
        $slow = $target->diagnostics()->logs()->slowQueries();
        $status = $target->diagnostics()->logs()->status();
        $target->close();

        self::assertSame(Availability::PermissionDenied, $error->status->availability);
        self::assertSame($privilege, $error->status->privilege);
        self::assertSame([], $error->entries);
        self::assertSame(Availability::PermissionDenied, $slow->status->availability);
        self::assertSame('SELECT ON mysql.slow_log', $slow->status->privilege);
        self::assertSame(Availability::PermissionDenied, $status->availability);
    }
}
