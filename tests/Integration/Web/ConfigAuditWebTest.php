<?php

declare(strict_types=1);

namespace App\Tests\Integration\Web;

use App\Core\Diagnostics\SecretVariables;
use App\Driver\ClickHouse\ClickHouseHttp;
use App\Driver\DbalConnector;
use App\Tests\Integration\Driver\RedisTarget;
use App\Tests\Integration\Driver\TargetDatabase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\HttpClient\HttpClient;

/**
 * L'audit de configuration et la comparaison de deux connexions, par la porte web, sur les vraies cibles. Les constats attendus
 * sont ceux du schéma de test par défaut, relus un à un : aucun n'est grave, chacun est vrai de la configuration du conteneur.
 */
#[Group('integration')]
final class ConfigAuditWebTest extends WebApiTestCase
{
    private function saveTarget(string $envVar, string $name = 'Target'): string
    {
        $parameters = TargetDatabase::parameters($envVar);
        $created = $this->call('POST', '/web/connections', ['name' => $name, 'engine' => TargetDatabase::engine($envVar)->value, 'host' => $parameters->host, 'port' => $parameters->port, 'database' => $parameters->database, 'username' => $parameters->username, 'password' => $parameters->password()]);
        $id = self::arrayOf($created['connection'])['id'];
        self::assertIsString($id);

        return $id;
    }

    /**
     * @return iterable<string, array{string, list<string>}>
     */
    public static function expectedFindings(): iterable
    {
        yield 'MySQL' => ['TARGET_MYSQL_URL', ['slow_query_log_off']];
        yield 'MySQL 26' => ['TARGET_MYSQL26_URL', ['slow_query_log_off']];
        yield 'MariaDB' => ['TARGET_MARIADB_URL', ['local_infile_enabled', 'performance_schema_off', 'secure_file_priv_empty', 'slow_query_log_off']];
        yield 'MariaDB 13' => ['TARGET_MARIADB13_URL', ['local_infile_enabled', 'performance_schema_off', 'secure_file_priv_empty', 'slow_query_log_off']];
        yield 'PostgreSQL' => ['TARGET_POSTGRES_URL', ['slow_statement_log_off', 'ssl_off']];
    }

    public function testRequiresASession(): void
    {
        $this->call('GET', '/web/connections/00000000-0000-7000-8000-000000000000/diagnostics/config-audit');
        self::assertSame(401, $this->statusCode());

        $this->call('GET', '/web/diagnostics/compare?a=00000000-0000-7000-8000-000000000000&b=00000000-0000-7000-8000-000000000001');
        self::assertSame(401, $this->statusCode());
    }

    /**
     * @param list<string> $expected
     */
    #[DataProvider('expectedFindings')]
    public function testTheDefaultTargetRaisesOnlyTheExpectedFindings(string $envVar, array $expected): void
    {
        $this->seedAndLogin();
        $id = $this->saveTarget($envVar);

        $body = $this->call('GET', '/web/connections/'.$id.'/diagnostics/config-audit');

        self::assertSame(200, $this->statusCode());
        self::assertSame(['status', 'engine', 'evaluated', 'rulesTotal', 'findings'], array_keys($body));
        self::assertSame('available', self::arrayOf($body['status'])['availability']);
        $findings = $body['findings'];
        self::assertIsArray($findings);
        $rules = array_map(static fn ($f): mixed => self::arrayOf($f)['rule'], $findings);
        sort($rules);
        self::assertSame($expected, $rules);
        foreach ($findings as $finding) {
            $finding = self::arrayOf($finding);
            self::assertSame(['rule', 'severity', 'variable', 'value', 'recommended', 'suggestion', 'explanation'], array_keys($finding));
            self::assertNotSame('high', $finding['severity'], 'No grave finding on the default test schema.');
            self::assertNotSame('', $finding['explanation']);
        }
        self::assertGreaterThanOrEqual(8, $body['evaluated'], 'The rules could read their variables.');
    }

    public function testClickHouseHasNoRulesAndSaysSo(): void
    {
        $this->seedAndLogin();
        $id = $this->saveTarget('TARGET_CLICKHOUSE_URL');

        $body = $this->call('GET', '/web/connections/'.$id.'/diagnostics/config-audit');

        self::assertSame(200, $this->statusCode());
        self::assertSame(['availability' => 'unsupported', 'reason' => 'no_rules', 'privilege' => null], $body['status']);
        self::assertSame([], $body['findings']);
    }

    public function testClickHouseWithoutTheGrantAnswersAState(): void
    {
        $parameters = TargetDatabase::parameters('TARGET_CLICKHOUSE_URL');
        $user = 'tdb_cfgw_'.bin2hex(random_bytes(4));
        $password = bin2hex(random_bytes(12));
        $http = new ClickHouseHttp(HttpClient::create(), $parameters);
        $http->query(\sprintf("CREATE USER %s IDENTIFIED WITH sha256_password BY '%s'", $user, $password));
        $http->query(\sprintf('GRANT SELECT ON shop.* TO %s', $user));
        try {
            $this->seedAndLogin();
            $created = $this->call('POST', '/web/connections', ['name' => 'Reduced', 'engine' => 'clickhouse', 'host' => $parameters->host, 'port' => $parameters->port, 'database' => $parameters->database, 'username' => $user, 'password' => $password]);
            $id = self::arrayOf($created['connection'])['id'];
            self::assertIsString($id);

            $body = $this->call('GET', '/web/connections/'.$id.'/diagnostics/config-audit');

            self::assertSame(200, $this->statusCode());
            self::assertSame('permission_denied', self::arrayOf($body['status'])['availability']);
            self::assertSame([], $body['findings']);
        } finally {
            $http->query(\sprintf('DROP USER IF EXISTS %s', $user));
        }
    }

    public function testRedisIsNotSupported(): void
    {
        RedisTarget::seeded();
        $this->seedAndLogin();
        $parameters = TargetDatabase::parameters(RedisTarget::ENV);
        $created = $this->call('POST', '/web/connections', ['name' => 'Cache', 'engine' => 'redis', 'host' => $parameters->host, 'port' => $parameters->port, 'database' => $parameters->database, 'username' => $parameters->username, 'password' => $parameters->password()]);
        $id = self::arrayOf($created['connection'])['id'];
        self::assertIsString($id);

        $this->call('GET', '/web/connections/'.$id.'/diagnostics/config-audit');

        self::assertSame(501, $this->statusCode());
    }

    public function testTheAuditNamesNeitherVariablesNorValues(): void
    {
        $user = $this->seedAndLogin();
        $id = $this->saveTarget('TARGET_POSTGRES_URL');

        $this->call('GET', '/web/connections/'.$id.'/diagnostics/config-audit');

        $events = array_values(array_filter($this->auditEvents($user, 'diagnostic'), static fn ($e): bool => 'config_audit' === ($e->getDetails()['topic'] ?? null)));
        self::assertCount(1, $events);
        $details = $events[0]->getDetails();
        self::assertSame('available', $details['status']);
        self::assertSame(2, $details['findings']);
        $text = json_encode($details, \JSON_THROW_ON_ERROR);
        foreach (['ssl', 'log_min_duration', 'fsync'] as $leak) {
            self::assertStringNotContainsString($leak, $text);
        }
    }

    public function testAnotherUsersConnectionIsNotFound(): void
    {
        $this->seedAndLogin('alice');
        $id = $this->saveTarget('TARGET_POSTGRES_URL');
        $this->call('POST', '/web/logout');
        $this->seedUser('bob');
        $this->login('bob');

        $this->call('GET', '/web/connections/'.$id.'/diagnostics/config-audit');

        self::assertSame(404, $this->statusCode());
    }

    public function testTwoCopiesOfTheSameServerDoNotDiffer(): void
    {
        $this->seedAndLogin();
        $a = $this->saveTarget('TARGET_MYSQL_URL', 'One');
        $b = $this->saveTarget('TARGET_MYSQL_URL', 'Two');

        $body = $this->call('GET', '/web/diagnostics/compare?a='.$a.'&b='.$b);

        self::assertSame(200, $this->statusCode());
        self::assertSame(['status', 'unavailableSide', 'masked', 'truncated', 'differences'], array_keys($body));
        self::assertSame('available', self::arrayOf($body['status'])['availability']);
        self::assertSame([], $body['differences']);
        self::assertIsInt($body['masked']);
    }

    public function testARuntimeChangeShowsAsADifferenceWithBothValues(): void
    {
        $this->seedAndLogin();
        $a = $this->saveTarget('TARGET_MYSQL_URL', 'Lts');
        $b = $this->saveTarget('TARGET_MYSQL26_URL', 'Latest');
        $admin = (new DbalConnector())->connect(TargetDatabase::engine('TARGET_MYSQL_URL'), TargetDatabase::parameters('TARGET_MYSQL_URL'));
        $raw = $admin->fetchOne('SELECT @@GLOBAL.net_write_timeout');
        self::assertIsNumeric($raw);
        $original = (int) $raw;
        try {
            $admin->executeStatement('SET GLOBAL net_write_timeout = 77');

            $body = $this->call('GET', '/web/diagnostics/compare?a='.$a.'&b='.$b);

            self::assertSame(200, $this->statusCode());
            $differences = $body['differences'];
            self::assertIsArray($differences);
            $byName = [];
            foreach ($differences as $difference) {
                $difference = self::arrayOf($difference);
                self::assertIsString($difference['name']);
                self::assertFalse(SecretVariables::matches($difference['name']), 'A masked variable is never listed.');
                $byName[$difference['name']] = $difference;
            }
            self::assertArrayHasKey('net_write_timeout', $byName);
            self::assertSame(['name', 'presentA', 'valueA', 'changedA', 'presentB', 'valueB', 'changedB', 'unit'], array_keys($byName['net_write_timeout']));
            self::assertSame('77', $byName['net_write_timeout']['valueA']);
            self::assertTrue($byName['net_write_timeout']['changedA']);
            self::assertNotSame('77', $byName['net_write_timeout']['valueB']);
        } finally {
            $admin->executeStatement('SET GLOBAL net_write_timeout = '.$original);
            $admin->close();
        }
    }

    public function testTwoDifferentEnginesCannotBeCompared(): void
    {
        $this->seedAndLogin();
        $a = $this->saveTarget('TARGET_MYSQL_URL', 'My');
        $b = $this->saveTarget('TARGET_POSTGRES_URL', 'Pg');

        $body = $this->call('GET', '/web/diagnostics/compare?a='.$a.'&b='.$b);

        self::assertSame(422, $this->statusCode());
        self::assertSame('invalid_input', $body['error']);
    }

    public function testAConnectionIsNotComparedWithItself(): void
    {
        $this->seedAndLogin();
        $a = $this->saveTarget('TARGET_MYSQL_URL');

        $body = $this->call('GET', '/web/diagnostics/compare?a='.$a.'&b='.$a);

        self::assertSame(422, $this->statusCode());
        self::assertSame('invalid_input', $body['error']);
    }

    public function testBadIdentifiersAreRefused(): void
    {
        $this->seedAndLogin();

        $this->call('GET', '/web/diagnostics/compare?a=nope&b=nope');
        self::assertSame(422, $this->statusCode());

        $this->call('GET', '/web/diagnostics/compare');
        self::assertSame(422, $this->statusCode());
    }

    public function testAnotherUsersConnectionIsIndistinguishableFromAMissingOne(): void
    {
        $this->seedAndLogin('alice');
        $theirs = $this->saveTarget('TARGET_MYSQL_URL');
        $this->call('POST', '/web/logout');
        $this->seedUser('bob');
        $this->login('bob');
        $mine = $this->saveTarget('TARGET_MYSQL_URL');

        $this->call('GET', '/web/diagnostics/compare?a='.$mine.'&b='.$theirs);
        $foreign = $this->statusCode();
        $this->call('GET', '/web/diagnostics/compare?a='.$mine.'&b=00000000-0000-7000-8000-000000000000');

        self::assertSame(404, $foreign);
        self::assertSame(404, $this->statusCode());
    }

    public function testAMemberCannotCompareWithAConnectionToTheServerItself(): void
    {
        $this->seedAndLogin('alice');
        $parameters = TargetDatabase::parameters('TARGET_MYSQL_URL');
        $mine = $this->saveTarget('TARGET_MYSQL_URL', 'Mine');
        $loop = $this->call('POST', '/web/connections', ['name' => 'Loop', 'engine' => 'mysql', 'host' => '127.0.0.1', 'port' => $parameters->port, 'database' => $parameters->database, 'username' => $parameters->username, 'password' => $parameters->password()]);
        $loopId = self::arrayOf($loop['connection'])['id'];
        self::assertIsString($loopId);
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $entityManager->getConnection()->executeStatement("UPDATE app_user SET role = 'member'");

        foreach (['a='.$mine.'&b='.$loopId, 'a='.$loopId.'&b='.$mine] as $query) {
            $body = $this->call('GET', '/web/diagnostics/compare?'.$query);

            self::assertSame(403, $this->statusCode(), $query);
            self::assertSame('forbidden_target', $body['error']);
        }
    }

    public function testTheComparisonIsAuditedWithCountsOnly(): void
    {
        $user = $this->seedAndLogin();
        $a = $this->saveTarget('TARGET_MYSQL_URL', 'One');
        $b = $this->saveTarget('TARGET_MYSQL_URL', 'Two');

        $this->call('GET', '/web/diagnostics/compare?a='.$a.'&b='.$b);

        $events = array_values(array_filter($this->auditEvents($user, 'diagnostic'), static fn ($e): bool => 'compare_variables' === ($e->getDetails()['topic'] ?? null)));
        self::assertCount(1, $events);
        $details = $events[0]->getDetails();
        self::assertSame('available', $details['status']);
        self::assertSame(0, $details['differences']);
        self::assertSame($b, $details['with']);
    }
}
