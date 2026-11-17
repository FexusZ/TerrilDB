<?php

declare(strict_types=1);

namespace App\Tests\Integration\Driver;

use App\Core\Connection\ConnectionParameters;
use App\Core\Connection\Engine;
use App\Core\Diagnostics\Availability;
use App\Core\Diagnostics\ConfigAuditor;
use App\Core\Diagnostics\ConfigFinding;
use App\Core\Diagnostics\VariableQuery;
use App\Core\Driver\TargetConnection;
use App\Driver\ClickHouse\ClickHouseDriver;
use App\Driver\ClickHouse\ClickHouseHttp;
use App\Driver\DbalConnector;
use App\Driver\MySql\MySqlDriver;
use App\Driver\PostgreSql\PostgreSqlDriver;
use App\Tests\Integration\Support\TestAccounts;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\HttpClient;

/**
 * L'audit de configuration lu sur les vraies cibles, avec le compte d'administration puis avec un compte à droits réduits.
 */
#[Group('integration')]
final class ConfigAuditTest extends TestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function sqlTargets(): iterable
    {
        yield from TargetDatabase::relational();
    }

    private function open(string $envVar, ?ConnectionParameters $parameters = null): TargetConnection
    {
        $engine = TargetDatabase::engine($envVar);
        $driver = match ($engine) {
            Engine::MySql, Engine::MariaDb => new MySqlDriver(new DbalConnector()),
            Engine::PostgreSql => new PostgreSqlDriver(new DbalConnector()),
            Engine::ClickHouse => new ClickHouseDriver(HttpClient::create()),
            Engine::Redis => throw new \LogicException('Redis is not audited.'),
        };

        return $driver->connect($engine, $parameters ?? TargetDatabase::parameters($envVar));
    }

    /**
     * @return array{string, list<string>, int} état, règles, nombre de règles évaluées
     */
    private function audit(TargetConnection $target): array
    {
        $variables = $target->diagnostics()->variables();
        $names = ConfigAuditor::namesFor($target->engine);
        $read = $variables->variables(new VariableQuery(limit: VariableQuery::MAX_LIMIT, names: $names));
        $report = (new ConfigAuditor())->audit($target->engine, $read);
        $rules = array_map(static fn (ConfigFinding $f): string => $f->rule->value, $report->findings);
        sort($rules);

        return [$report->status->availability->value, $rules, $report->evaluated];
    }

    #[DataProvider('sqlTargets')]
    public function testEveryRuleVariableExistsOnTheTarget(string $envVar): void
    {
        $target = $this->open($envVar);
        $engine = $target->engine;

        $report = $target->diagnostics()->variables()->variables(new VariableQuery(limit: VariableQuery::MAX_LIMIT, names: ConfigAuditor::namesFor($engine)));

        $found = array_map(static fn ($v): string => $v->name, $report->variables);
        $missing = array_values(array_diff(ConfigAuditor::namesFor($engine), $found));
        // MySQL 8.4 et 26 ont retiré expire_logs_days, MariaDB n'a pas innodb_doublewrite en lecture seule partout : seules ces
        // absences sont connues, toute autre serait une règle qui ne juge jamais rien.
        $known = Engine::MySql === $engine ? ['expire_logs_days'] : [];
        self::assertSame($known, $missing, 'Variables a rule reads but the server does not list.');
        $target->close();
    }

    #[DataProvider('sqlTargets')]
    public function testAnAccountWithoutAnyGrantReadsTheSameVariablesAsTheAdministrator(string $envVar): void
    {
        $admin = $this->open($envVar);
        $expected = $this->audit($admin);
        $admin->close();

        $actual = TestAccounts::using($envVar, function (TestAccounts $accounts) use ($envVar): array {
            $account = $accounts->limited();
            $parameters = TargetDatabase::parameters($envVar);
            $engine = TargetDatabase::engine($envVar);
            $reduced = $this->open($envVar, new ConnectionParameters($parameters->host, $parameters->port, Engine::PostgreSql === $engine ? $parameters->database : '', $account->name, $account->password));
            try {
                return $this->audit($reduced);
            } finally {
                $reduced->close();
            }
        });

        self::assertSame('available', $expected[0]);
        self::assertSame('available', $actual[0], 'The catalogs holding the variables are readable by any account.');
        // Un compte sans droit peut voir moins de variables (PostgreSQL laisse vides celles que seul pg_read_all_settings lit) : la règle
        // concernée se tait et n'est pas comptée, mais aucun constat n'apparaît ni ne disparaît pour autant.
        self::assertSame($expected[1], $actual[1]);
        self::assertLessThanOrEqual($expected[2], $actual[2]);
    }

    public function testClickHouseWithoutTheGrantGetsAStateAndNoRule(): void
    {
        $parameters = TargetDatabase::parameters('TARGET_CLICKHOUSE_URL');
        $user = 'tdb_cfg_'.bin2hex(random_bytes(4));
        $password = bin2hex(random_bytes(12));
        $http = new ClickHouseHttp(HttpClient::create(), $parameters);
        $http->query(\sprintf("CREATE USER %s IDENTIFIED WITH sha256_password BY '%s'", $user, $password));
        $http->query(\sprintf('GRANT SELECT ON shop.* TO %s', $user));
        try {
            $reduced = $this->open('TARGET_CLICKHOUSE_URL', new ConnectionParameters($parameters->host, $parameters->port, $parameters->database, $user, $password));
            $status = $reduced->diagnostics()->variables()->status();
            $report = (new ConfigAuditor())->audit(Engine::ClickHouse, new \App\Core\Diagnostics\VariableReport($status, [], false));
            $reduced->close();

            self::assertSame(Availability::PermissionDenied, $report->status->availability);
            self::assertSame([], $report->findings);
        } finally {
            $http->query(\sprintf('DROP USER IF EXISTS %s', $user));
        }
    }
}
