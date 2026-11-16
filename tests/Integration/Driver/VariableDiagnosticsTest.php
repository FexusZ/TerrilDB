<?php

declare(strict_types=1);

namespace App\Tests\Integration\Driver;

use App\Core\Connection\ConnectionParameters;
use App\Core\Connection\Engine;
use App\Core\Diagnostics\Availability;
use App\Core\Diagnostics\ServerVariable;
use App\Core\Diagnostics\VariableQuery;
use App\Core\Driver\Driver;
use App\Core\Driver\Exception\TargetConnectionFailedException;
use App\Core\Driver\TargetConnection;
use App\Driver\ClickHouse\ClickHouseDriver;
use App\Driver\ClickHouse\ClickHouseHttp;
use App\Driver\DbalConnector;
use App\Driver\MySql\MySqlDriver;
use App\Driver\PostgreSql\PostgreSqlDriver;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\HttpClient;

/**
 * Les variables de configuration, lues sur les vrais moteurs (lecture seule : le seul SET du test passe par une connexion
 * d'administration à part, et il est remis en fin de test).
 */
#[Group('integration')]
final class VariableDiagnosticsTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function targets(): iterable
    {
        yield 'MySQL' => ['TARGET_MYSQL_URL', 'max_connections'];
        yield 'MySQL 26' => ['TARGET_MYSQL26_URL', 'max_connections'];
        yield 'MariaDB' => ['TARGET_MARIADB_URL', 'max_connections'];
        yield 'MariaDB 13' => ['TARGET_MARIADB13_URL', 'max_connections'];
        yield 'PostgreSQL' => ['TARGET_POSTGRES_URL', 'max_connections'];
        yield 'ClickHouse' => ['TARGET_CLICKHOUSE_URL', 'max_threads'];
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function allTargets(): iterable
    {
        foreach (self::targets() as $label => [$envVar]) {
            yield $label => [$envVar];
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function sqlTargets(): iterable
    {
        yield from TargetDatabase::relational();
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

    /**
     * @param list<ServerVariable> $variables
     */
    private static function find(array $variables, string $name): ?ServerVariable
    {
        foreach ($variables as $variable) {
            if ($variable->name === $name) {
                return $variable;
            }
        }

        return null;
    }

    #[DataProvider('targets')]
    public function testReadsTheVariablesWithTheirValue(string $envVar, string $name): void
    {
        $target = $this->open($envVar);

        $report = $target->diagnostics()->variables()->variables(new VariableQuery(search: $name));

        self::assertSame(Availability::Available, $report->status->availability);
        $variable = self::find($report->variables, $name);
        self::assertNotNull($variable, $name.' is listed');
        self::assertNotNull($variable->value);
        self::assertFalse($variable->masked);
        foreach ($report->variables as $listed) {
            self::assertStringContainsString($name, $listed->name);
        }
        $target->close();
    }

    #[DataProvider('targets')]
    public function testTheSearchReadsPercentAndUnderscoreLiterally(string $envVar, string $name): void
    {
        $target = $this->open($envVar);
        $diagnostics = $target->diagnostics()->variables();

        self::assertSame([], $diagnostics->variables(new VariableQuery(search: str_replace('_', '%', $name)))->variables);
        self::assertSame([], $diagnostics->variables(new VariableQuery(search: str_replace('_', '-', $name)))->variables);
        self::assertNotNull(self::find($diagnostics->variables(new VariableQuery(search: strtoupper($name)))->variables, $name), 'The search ignores the case.');
        $target->close();
    }

    #[DataProvider('allTargets')]
    public function testTheLimitIsRespectedAndSaid(string $envVar): void
    {
        $target = $this->open($envVar);

        $report = $target->diagnostics()->variables()->variables(new VariableQuery(limit: 3));

        self::assertCount(3, $report->variables);
        self::assertTrue($report->truncated);
        $names = array_map(static fn (ServerVariable $v): string => $v->name, $report->variables);
        $sorted = $names;
        sort($sorted, \SORT_STRING);
        self::assertSame($sorted, $names, 'Byte order.');
        $target->close();
    }

    #[DataProvider('allTargets')]
    public function testChangedOnlyKeepsWhatDiffersFromTheDefault(string $envVar): void
    {
        $target = $this->open($envVar);

        $report = $target->diagnostics()->variables()->variables(new VariableQuery(changedOnly: true, limit: 500));
        $all = $target->diagnostics()->variables()->variables(new VariableQuery(limit: 500));

        self::assertNotSame([], $report->variables);
        foreach ($report->variables as $variable) {
            self::assertTrue($variable->changed, $variable->name);
        }
        self::assertLessThan(\count($all->variables), \count($report->variables), 'Not everything differs.');
        $target->close();
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function secretNames(): iterable
    {
        yield 'MySQL' => ['TARGET_MYSQL_URL', 'ssl_key'];
        yield 'MySQL 26' => ['TARGET_MYSQL26_URL', 'ssl_key'];
        yield 'MariaDB' => ['TARGET_MARIADB_URL', 'ssl_key'];
        yield 'MariaDB 13' => ['TARGET_MARIADB13_URL', 'ssl_key'];
        yield 'PostgreSQL' => ['TARGET_POSTGRES_URL', 'ssl_key_file'];
        // Des valeurs qui portent des identifiants sans que le nom dise « mot de passe ».
        yield 'MySQL init_connect' => ['TARGET_MYSQL_URL', 'init_connect'];
        yield 'MySQL 26 init_connect' => ['TARGET_MYSQL26_URL', 'init_connect'];
        yield 'MariaDB init_connect' => ['TARGET_MARIADB_URL', 'init_connect'];
        yield 'MariaDB 13 init_connect' => ['TARGET_MARIADB13_URL', 'init_connect'];
        yield 'MariaDB 13 wsrep_notify_cmd' => ['TARGET_MARIADB13_URL', 'wsrep_notify_cmd'];
        yield 'PostgreSQL primary_conninfo' => ['TARGET_POSTGRES_URL', 'primary_conninfo'];
        yield 'PostgreSQL archive_command' => ['TARGET_POSTGRES_URL', 'archive_command'];
        yield 'PostgreSQL restore_command' => ['TARGET_POSTGRES_URL', 'restore_command'];
    }

    #[DataProvider('secretNames')]
    public function testAVariableThatSuggestsASecretComesWithoutItsValue(string $envVar, string $name): void
    {
        $target = $this->open($envVar);

        $variable = self::find($target->diagnostics()->variables()->variables(new VariableQuery(search: $name))->variables, $name);

        self::assertNotNull($variable);
        self::assertTrue($variable->masked);
        self::assertNull($variable->value);
        self::assertNull($variable->default);
        $target->close();
    }

    #[DataProvider('sqlTargets')]
    public function testTheCapabilityIsAvailable(string $envVar): void
    {
        $target = $this->open($envVar);

        self::assertSame(Availability::Available, $target->diagnostics()->capabilities()['variables']->availability);
        $target->close();
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function runtimeChange(): iterable
    {
        yield 'MySQL' => ['TARGET_MYSQL_URL', 'DYNAMIC'];
        yield 'MySQL 26' => ['TARGET_MYSQL26_URL', 'DYNAMIC'];
        yield 'MariaDB' => ['TARGET_MARIADB_URL', 'SQL'];
        yield 'MariaDB 13' => ['TARGET_MARIADB13_URL', 'SQL'];
    }

    #[DataProvider('runtimeChange')]
    public function testARuntimeChangeShowsAsChangedWithItsOrigin(string $envVar, string $origin): void
    {
        $admin = (new DbalConnector())->connect(TargetDatabase::engine($envVar), TargetDatabase::parameters($envVar));
        $target = $this->open($envVar);
        $before = self::find($target->diagnostics()->variables()->variables(new VariableQuery(search: 'net_write_timeout'))->variables, 'net_write_timeout');
        self::assertNotNull($before);
        $original = $before->value;
        self::assertNotNull($original);
        try {
            $admin->executeStatement('SET GLOBAL net_write_timeout = 77');

            $after = self::find($target->diagnostics()->variables()->variables(new VariableQuery(search: 'net_write_timeout'))->variables, 'net_write_timeout');

            self::assertNotNull($after);
            self::assertSame('77', $after->value);
            self::assertTrue($after->changed);
            self::assertSame($origin, $after->source);
        } finally {
            $admin->executeStatement('SET GLOBAL net_write_timeout = '.(int) $original);
            $admin->close();
            $target->close();
        }
    }

    public function testPostgreSqlTellsWhatNeedsARestartAndTheUnit(): void
    {
        $target = $this->open('TARGET_POSTGRES_URL');

        $variables = $target->diagnostics()->variables()->variables(new VariableQuery(search: 'shared_buffers'))->variables;

        $variable = self::find($variables, 'shared_buffers');
        self::assertNotNull($variable);
        self::assertSame(\App\Core\Diagnostics\RestartNeed::Required, $variable->restart);
        self::assertSame('8kB', $variable->unit);
        self::assertNotNull($variable->source);
        $target->close();
    }

    public function testMariaDbGivesTheDefaultAndReadOnly(): void
    {
        $target = $this->open('TARGET_MARIADB_URL');

        $variable = self::find($target->diagnostics()->variables()->variables(new VariableQuery(search: 'back_log'))->variables, 'back_log');

        self::assertNotNull($variable);
        self::assertSame(\App\Core\Diagnostics\RestartNeed::Required, $variable->restart);
        self::assertNotNull($variable->default);
        self::assertSame('global', $variable->scope);
        $target->close();
    }

    public function testClickHouseSeparatesUserSettingsFromServerSettings(): void
    {
        $target = $this->open('TARGET_CLICKHOUSE_URL');

        $report = $target->diagnostics()->variables()->variables(new VariableQuery(search: 'max_', limit: 500));

        $scopes = array_unique(array_map(static fn (ServerVariable $v): ?string => $v->scope, $report->variables));
        sort($scopes);
        self::assertSame(['server', 'session'], $scopes);
        $target->close();
    }

    public function testClickHouseWithoutTheGrantGetsAState(): void
    {
        $admin = $this->open('TARGET_CLICKHOUSE_URL');
        $parameters = TargetDatabase::parameters('TARGET_CLICKHOUSE_URL');
        $user = 'tdb_var_'.bin2hex(random_bytes(4));
        $password = bin2hex(random_bytes(12));
        $http = new ClickHouseHttp(HttpClient::create(), $parameters);
        $http->query(\sprintf("CREATE USER %s IDENTIFIED WITH sha256_password BY '%s'", $user, $password));
        $http->query(\sprintf('GRANT SELECT ON shop.* TO %s', $user));
        try {
            $reduced = $this->open('TARGET_CLICKHOUSE_URL', new ConnectionParameters($parameters->host, $parameters->port, $parameters->database, $user, $password));
            $report = $reduced->diagnostics()->variables()->variables(new VariableQuery());
            $status = $reduced->diagnostics()->variables()->status();
            $reduced->close();

            self::assertSame(Availability::PermissionDenied, $report->status->availability);
            self::assertSame('SELECT ON system.server_settings', $status->privilege);
            self::assertSame([], $report->variables);

            $http->query(\sprintf('GRANT SELECT ON system.server_settings TO %s', $user));
            $granted = $this->open('TARGET_CLICKHOUSE_URL', new ConnectionParameters($parameters->host, $parameters->port, $parameters->database, $user, $password));
            self::assertSame(Availability::Available, $granted->diagnostics()->variables()->status()->availability);
            $granted->close();
        } finally {
            $http->query(\sprintf('DROP USER IF EXISTS %s', $user));
            $admin->close();
        }
    }
}
