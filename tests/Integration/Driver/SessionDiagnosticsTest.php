<?php

declare(strict_types=1);

namespace App\Tests\Integration\Driver;

use App\Core\Connection\ConnectionParameters;
use App\Core\Connection\Engine;
use App\Core\Diagnostics\Availability;
use App\Core\Diagnostics\ServerSession;
use App\Core\Driver\Driver;
use App\Core\Driver\Exception\TargetConnectionFailedException;
use App\Core\Driver\TargetConnection;
use App\Driver\ClickHouse\ClickHouseDriver;
use App\Driver\ClickHouse\ClickHouseHttp;
use App\Driver\DbalConnector;
use App\Driver\MySql\MySqlDriver;
use App\Driver\PostgreSql\PostgreSqlDriver;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\HttpClient;

/**
 * Les sessions du serveur, lues sur les vrais moteurs, avec le compte de la cible puis un compte aux droits réduits
 * (créé puis supprimé par le test).
 */
#[Group('integration')]
final class SessionDiagnosticsTest extends TestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function targets(): iterable
    {
        yield from TargetDatabase::relational();
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function targetsWithPrivilege(): iterable
    {
        yield 'MySQL' => ['TARGET_MYSQL_URL', 'PROCESS'];
        yield 'MySQL 26' => ['TARGET_MYSQL26_URL', 'PROCESS'];
        yield 'MariaDB' => ['TARGET_MARIADB_URL', 'PROCESS'];
        yield 'MariaDB 13' => ['TARGET_MARIADB13_URL', 'PROCESS'];
        yield 'PostgreSQL' => ['TARGET_POSTGRES_URL', 'pg_read_all_stats'];
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

    private function withUser(ConnectionParameters $admin, string $user, string $password): ConnectionParameters
    {
        return new ConnectionParameters($admin->host, $admin->port, $admin->database, $user, $password);
    }

    #[DataProvider('targets')]
    public function testTheAccountOfTheTargetSeesItsOwnSessionAndOthers(string $envVar): void
    {
        $other = $this->open($envVar);
        $target = $this->open($envVar);

        $report = $target->diagnostics()->sessions()->sessions();

        self::assertSame(Availability::Available, $report->status->availability);
        self::assertGreaterThanOrEqual(2, \count($report->sessions));
        $current = array_values(array_filter($report->sessions, static fn (ServerSession $s): bool => $s->current));
        self::assertCount(1, $current);
        self::assertSame(TargetDatabase::parameters($envVar)->username, $current[0]->user);
        self::assertSame('shop', $current[0]->database);
        self::assertNotNull($current[0]->seconds);
        foreach ($report->sessions as $session) {
            self::assertLessThanOrEqual(ServerSession::MAX_QUERY_LENGTH, mb_strlen($session->query ?? ''));
            self::assertStringNotContainsString(':', $session->client ?? '', 'The client address has no port.');
        }
        $other->close();
        $target->close();
    }

    #[DataProvider('targets')]
    public function testItIsLimitedAndSaysSoWhenTheServerHasMore(string $envVar): void
    {
        $target = $this->open($envVar);

        $report = $target->diagnostics()->sessions()->sessions();

        self::assertFalse($report->truncated);
        $target->close();
    }

    #[DataProvider('targetsWithPrivilege')]
    public function testAnAccountWithoutTheRightGetsAStateNotAnError(string $envVar, string $privilege): void
    {
        $parameters = TargetDatabase::parameters($envVar);
        $user = 'tdb_diag_'.bin2hex(random_bytes(4));
        $password = bin2hex(random_bytes(12));

        $this->createReducedUser($envVar, $user, $password);
        try {
            $reduced = $this->open($envVar, $this->withUser($parameters, $user, $password));
            $status = $reduced->diagnostics()->sessions()->status();
            $report = $reduced->diagnostics()->sessions()->sessions();
            $reduced->close();

            self::assertSame(Availability::PermissionDenied, $status->availability);
            self::assertSame($privilege, $status->privilege);
            self::assertSame('insufficient_privilege', $status->reason);
            self::assertSame(Availability::PermissionDenied, $report->status->availability);
            self::assertSame([], $report->sessions);

            $this->grantMonitoring($envVar, $user);
            $granted = $this->open($envVar, $this->withUser($parameters, $user, $password));
            self::assertSame(Availability::Available, $granted->diagnostics()->sessions()->status()->availability);
            $granted->close();
        } finally {
            $this->dropUser($envVar, $user);
        }
    }

    #[DataProvider('targets')]
    public function testTheLiteralsOfAnotherSessionAreNeverShown(string $envVar): void
    {
        $engine = TargetDatabase::engine($envVar);
        $secret = 'tdb-secret-'.bin2hex(random_bytes(6));
        $sql = Engine::PostgreSql === $engine
            ? \sprintf("SELECT pg_sleep(6), '%s', 4242", $secret)
            : \sprintf("SELECT SLEEP(6), '%s', 4242", $secret);

        $this->observeOtherSession($envVar, $sql, $secret, Engine::PostgreSql === $engine ? 'pg_sleep' : 'SLEEP');
    }

    public function testClickHouseNeverShowsTheLiteralsOfAnotherSession(): void
    {
        $secret = 'tdb-secret-'.bin2hex(random_bytes(6));

        $this->observeOtherSession('TARGET_CLICKHOUSE_URL', \sprintf("SELECT sleep(3), '%s', 4242", $secret), $secret, 'sleep');
    }

    /**
     * Une AUTRE session (un processus PHP à part, qui exécute vraiment la requête) est observée pendant qu'elle tourne.
     */
    private function observeOtherSession(string $envVar, string $sql, string $secret, string $marker): void
    {
        $target = $this->open($envVar);
        $parameters = TargetDatabase::parameters($envVar);
        $engine = TargetDatabase::engine($envVar);
        $child = proc_open(
            [\PHP_BINARY, '-r', <<<'PHP'
                $c = json_decode((string) getenv('TDB_CHILD'), true);
                if ('clickhouse' === $c['engine']) {
                    $context = stream_context_create(['http' => ['method' => 'POST', 'content' => $c['sql'], 'header' => 'X-ClickHouse-User: '.$c['user']."\r\nX-ClickHouse-Key: ".$c['password']."\r\n", 'ignore_errors' => true]]);
                    file_get_contents('http://'.$c['host'].':'.$c['port'].'/', false, $context);
                } else {
                    $dsn = ('pgsql' === $c['engine'] ? 'pgsql' : 'mysql').':host='.$c['host'].';port='.$c['port'].';dbname='.$c['database'];
                    (new PDO($dsn, $c['user'], $c['password']))->query($c['sql']);
                }
                PHP],
            [['file', '/dev/null', 'r'], ['file', '/dev/null', 'w'], ['file', '/dev/null', 'w']],
            $pipes,
            null,
            ['TDB_CHILD' => json_encode([
                'engine' => match ($engine) {
                    Engine::PostgreSql => 'pgsql', Engine::ClickHouse => 'clickhouse', default => 'mysql',
                },
                'host' => $parameters->host,
                'port' => $parameters->port,
                'database' => $parameters->database,
                'user' => $parameters->username,
                'password' => $parameters->password(),
                'sql' => $sql,
            ], \JSON_THROW_ON_ERROR)] + getenv(),
        );
        self::assertIsResource($child);

        try {
            $seen = null;
            for ($attempt = 0; $attempt < 40 && null === $seen; ++$attempt) {
                usleep(150_000);
                foreach ($target->diagnostics()->sessions()->sessions()->sessions as $session) {
                    self::assertStringNotContainsString($secret, $session->query ?? '');
                    self::assertStringNotContainsString('4242', $session->query ?? '');
                    if (!$session->current && str_contains($session->query ?? '', $marker)) {
                        $seen = $session;
                    }
                }
            }

            self::assertNotNull($seen, 'The other session was not observed while it ran.');
            self::assertStringContainsString('?', $seen->query ?? '');
        } finally {
            proc_terminate($child);
            proc_close($child);
            $target->close();
        }
    }

    public function testClickHouseListsTheRunningQueryAsTheCurrentSession(): void
    {
        $target = $this->open('TARGET_CLICKHOUSE_URL');

        $report = $target->diagnostics()->sessions()->sessions();

        self::assertSame(Availability::Available, $report->status->availability);
        $current = array_values(array_filter($report->sessions, static fn (ServerSession $s): bool => $s->current));
        self::assertCount(1, $current);
        self::assertSame(TargetDatabase::parameters('TARGET_CLICKHOUSE_URL')->username, $current[0]->user);
        self::assertStringContainsString('system.processes', $current[0]->query ?? '');
        $target->close();
    }

    public function testClickHouseWithoutTheGrantGetsAState(): void
    {
        $admin = $this->open('TARGET_CLICKHOUSE_URL');
        $parameters = TargetDatabase::parameters('TARGET_CLICKHOUSE_URL');
        $user = 'tdb_diag_'.bin2hex(random_bytes(4));
        $password = bin2hex(random_bytes(12));
        $http = new ClickHouseHttp(HttpClient::create(), $parameters);
        $http->query(\sprintf("CREATE USER %s IDENTIFIED WITH sha256_password BY '%s'", $user, $password));
        $http->query(\sprintf('GRANT SELECT ON shop.* TO %s', $user));
        try {
            $reduced = $this->open('TARGET_CLICKHOUSE_URL', $this->withUser($parameters, $user, $password));
            $report = $reduced->diagnostics()->sessions()->sessions();
            $status = $reduced->diagnostics()->sessions()->status();
            $reduced->close();

            self::assertSame(Availability::PermissionDenied, $report->status->availability);
            self::assertSame('SELECT ON system.processes', $status->privilege);
            self::assertSame([], $report->sessions);

            $http->query(\sprintf('GRANT SELECT ON system.processes TO %s', $user));
            $granted = $this->open('TARGET_CLICKHOUSE_URL', $this->withUser($parameters, $user, $password));
            self::assertSame(Availability::Available, $granted->diagnostics()->sessions()->status()->availability);
            $granted->close();
        } finally {
            $http->query(\sprintf('DROP USER IF EXISTS %s', $user));
            $admin->close();
        }
    }

    private function createReducedUser(string $envVar, string $user, string $password): void
    {
        $connection = $this->adminConnection($envVar);
        if (Engine::PostgreSql === TargetDatabase::engine($envVar)) {
            $connection->executeStatement(\sprintf("CREATE ROLE %s LOGIN PASSWORD '%s'", $user, $password));

            return;
        }
        $connection->executeStatement(\sprintf("CREATE USER '%s'@'%%' IDENTIFIED BY '%s'", $user, $password));
        $connection->executeStatement(\sprintf("GRANT SELECT ON shop.* TO '%s'@'%%'", $user));
    }

    private function grantMonitoring(string $envVar, string $user): void
    {
        $connection = $this->adminConnection($envVar);
        if (Engine::PostgreSql === TargetDatabase::engine($envVar)) {
            $connection->executeStatement(\sprintf('GRANT pg_monitor TO %s', $user));

            return;
        }
        $connection->executeStatement(\sprintf("GRANT PROCESS ON *.* TO '%s'@'%%'", $user));
    }

    private function dropUser(string $envVar, string $user): void
    {
        $this->adminConnection($envVar)->executeStatement(Engine::PostgreSql === TargetDatabase::engine($envVar) ? \sprintf('DROP ROLE IF EXISTS %s', $user) : \sprintf("DROP USER IF EXISTS '%s'@'%%'", $user));
    }

    /**
     * Le compte d'administration du test passe par sa propre connexion DBAL : la console de TerrilDB refuse ces instructions.
     */
    private function adminConnection(string $envVar): Connection
    {
        return (new DbalConnector())->connect(TargetDatabase::engine($envVar), TargetDatabase::parameters($envVar));
    }
}
