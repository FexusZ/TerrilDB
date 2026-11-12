<?php

declare(strict_types=1);

namespace App\Tests\Integration\Driver;

use App\Core\Connection\ConnectionParameters;
use App\Core\Connection\Engine;
use App\Core\Diagnostics\Account;
use App\Core\Diagnostics\AccountReport;
use App\Core\Diagnostics\Availability;
use App\Core\Diagnostics\SecurityFinding;
use App\Core\Diagnostics\TlsRequirement;
use App\Core\Driver\Exception\TargetConnectionFailedException;
use App\Core\Driver\TargetConnection;
use App\Driver\DbalConnector;
use App\Driver\MySql\MySqlDriver;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Comptes et privilèges lus sur les vrais serveurs MySQL et MariaDB. Les comptes sont JETABLES (nom unique, supprimés dans
 * tearDown : un CREATE USER ne s'annule pas avec la transaction du test) ; aucun compte existant n'est touché.
 */
#[Group('integration')]
final class AccountDiagnosticsTest extends TestCase
{
    /** @var list<string> */
    private array $users = [];
    /** @var list<string> */
    private array $roles = [];
    private ?Connection $admin = null;

    protected function tearDown(): void
    {
        if (null !== $this->admin) {
            foreach ($this->users as $user) {
                $this->admin->executeStatement(\sprintf('DROP USER IF EXISTS %s', $user));
            }
            foreach ($this->roles as $role) {
                $this->admin->executeStatement(\sprintf('DROP ROLE IF EXISTS %s', $role));
            }
            $this->admin->close();
        }
        $this->admin = null;
        $this->users = [];
        $this->roles = [];
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function targets(): iterable
    {
        yield 'MySQL' => ['TARGET_MYSQL_URL'];
        yield 'MySQL 26' => ['TARGET_MYSQL26_URL'];
        yield 'MariaDB' => ['TARGET_MARIADB_URL'];
        yield 'MariaDB 13' => ['TARGET_MARIADB13_URL'];
    }

    private function admin(string $envVar): Connection
    {
        try {
            return $this->admin ??= (new DbalConnector())->connect(TargetDatabase::engine($envVar), TargetDatabase::parameters($envVar));
        } catch (TargetConnectionFailedException) {
            self::markTestSkipped(\sprintf('%s is unreachable.', $envVar));
        }
    }

    private function open(string $envVar, ?ConnectionParameters $parameters = null): TargetConnection
    {
        $engine = TargetDatabase::engine($envVar);

        return (new MySqlDriver(new DbalConnector()))->connect($engine, $parameters ?? TargetDatabase::parameters($envVar));
    }

    private function user(string $name, string $host): string
    {
        return $this->users[] = \sprintf("'%s'@'%s'", $name, $host);
    }

    private static function unique(): string
    {
        return 'tdb_acc_'.bin2hex(random_bytes(4));
    }

    /**
     * @param list<Account> $accounts
     */
    private static function find(array $accounts, string $user, string $host): Account
    {
        foreach ($accounts as $account) {
            if ($account->user === $user && $account->host === $host) {
                return $account;
            }
        }
        self::fail(\sprintf('Account %s@%s not listed.', $user, $host));
    }

    /**
     * @param list<SecurityFinding> $findings
     *
     * @return list<string>
     */
    private static function rulesFor(array $findings, string $user): array
    {
        $rules = [];
        foreach ($findings as $f) {
            if (null !== $f->account && str_starts_with($f->account, $user)) {
                $rules[] = $f->rule->value;
            }
        }
        $rules = array_values(array_unique($rules));
        sort($rules);

        return $rules;
    }

    private function report(string $envVar): AccountReport
    {
        $target = $this->open($envVar);
        $diagnostics = $target->diagnostics()->accounts();
        $status = $diagnostics->status();
        $report = $diagnostics->accounts();
        $target->close();

        self::assertSame(Availability::Available, $status->availability);
        self::assertSame(Availability::Available, $report->status->availability);

        return $report;
    }

    #[DataProvider('targets')]
    public function testDisposableAccountsAreReadWithTheirPluginPrivilegesAndRoles(string $envVar): void
    {
        $admin = $this->admin($envVar);
        $mariadb = Engine::MariaDb === TargetDatabase::engine($envVar);
        $name = self::unique();
        $role = $name.'_role';
        $password = bin2hex(random_bytes(10));
        $app = $this->user($name, '%');
        $local = $this->user($name, 'localhost');
        $idle = $this->user($name.'_i', '%');
        $this->roles[] = $mariadb ? $role : \sprintf("'%s'@'%%'", $role);

        $admin->executeStatement(\sprintf("CREATE USER %s IDENTIFIED BY '%s'", $app, $password));
        $admin->executeStatement(\sprintf('CREATE USER %s', $local));
        $admin->executeStatement(\sprintf("CREATE USER %s IDENTIFIED BY '%s'", $idle, $password));
        $admin->executeStatement(\sprintf('CREATE ROLE %s', $mariadb ? $role : "'".$role."'@'%'"));
        $admin->executeStatement(\sprintf('GRANT SELECT ON shop.* TO %s', $mariadb ? $role : "'".$role."'@'%'"));
        $roleRef = $mariadb ? $role : "'".$role."'@'%'";
        $admin->executeStatement(\sprintf('GRANT %s TO %s', $roleRef, $app));
        $admin->executeStatement(\sprintf('GRANT %s TO %s', $roleRef, $idle));
        $admin->executeStatement(\sprintf($mariadb ? 'SET DEFAULT ROLE %s FOR %s' : 'SET DEFAULT ROLE %s TO %s', $roleRef, $app));
        $admin->executeStatement(\sprintf('GRANT FILE ON *.* TO %s', $app));
        $admin->executeStatement(\sprintf('GRANT INSERT ON shop.* TO %s WITH GRANT OPTION', $app));
        $admin->executeStatement(\sprintf('ALTER USER %s PASSWORD EXPIRE', $idle));
        $admin->executeStatement(\sprintf('ALTER USER %s REQUIRE SSL', $idle));

        $report = $this->report($envVar);

        $a = self::find($report->accounts, $name, '%');
        self::assertTrue($a->passwordSet);
        self::assertFalse($a->passwordExpired);
        self::assertFalse($a->locked);
        self::assertSame(TlsRequirement::None, $a->tls);
        self::assertContains('FILE', $a->globalPrivileges);
        self::assertContains('INSERT', $a->databasePrivileges['shop']);
        self::assertContains('shop', $a->grantOptionOn);
        self::assertCount(1, $a->roles);
        self::assertStringStartsWith($role, $a->roles[0]);
        self::assertSame($a->roles, $a->defaultRoles);
        self::assertNotSame('', $a->plugin);

        self::assertFalse(self::find($report->accounts, $name, 'localhost')->passwordSet);

        $i = self::find($report->accounts, $name.'_i', '%');
        self::assertTrue($i->passwordExpired);
        self::assertSame(TlsRequirement::Any, $i->tls);
        self::assertSame([], $i->defaultRoles);

        $r = self::find($report->accounts, $role, $mariadb ? '' : '%');
        self::assertTrue($r->isRole);
        self::assertContains('SELECT', $r->databasePrivileges['shop']);

        $findings = $report->findings();
        self::assertSame(['dangerous_privilege', 'grant_option', 'no_tls_requirement', 'wildcard_host'], self::rulesFor($findings, $name.'@%'));
        self::assertContains('no_password', self::rulesFor($findings, $name.'@localhost'));
        self::assertContains('same_name_many_hosts', self::rulesFor($findings, $name));
        $idleRules = self::rulesFor($findings, $name.'_i');
        self::assertContains('password_expired', $idleRules);
        self::assertContains('role_never_active', $idleRules);
        self::assertNotContains('no_tls_requirement', $idleRules);
    }

    #[DataProvider('targets')]
    public function testTheAccountOfTheConnectionIsMarked(string $envVar): void
    {
        $report = $this->report($envVar);

        $current = array_values(array_filter($report->accounts, static fn (Account $a): bool => $a->current));
        self::assertCount(1, $current);
        self::assertSame(TargetDatabase::parameters($envVar)->username, $current[0]->user);
        self::assertNotSame([], $current[0]->globalPrivileges);
    }

    #[DataProvider('targets')]
    public function testNoPasswordHashEverAppearsInTheReport(string $envVar): void
    {
        $admin = $this->admin($envVar);
        $mariadb = Engine::MariaDb === TargetDatabase::engine($envVar);
        $name = self::unique();
        $user = $this->user($name, '%');
        $admin->executeStatement(\sprintf("CREATE USER %s IDENTIFIED BY '%s'", $user, bin2hex(random_bytes(10))));
        $hashes = $mariadb
            ? $admin->fetchFirstColumn("SELECT JSON_VALUE(Priv, '\$.authentication_string') FROM mysql.global_priv WHERE JSON_VALUE(Priv, '\$.authentication_string') <> ''")
            : $admin->fetchFirstColumn("SELECT authentication_string FROM mysql.user WHERE authentication_string <> ''");
        self::assertNotSame([], $hashes, 'The test needs a real hash to look for.');

        $json = json_encode($this->report($envVar), \JSON_THROW_ON_ERROR);

        foreach ($hashes as $hash) {
            self::assertIsString($hash);
            self::assertStringNotContainsString($hash, $json);
        }
    }

    #[DataProvider('targets')]
    public function testAnAccountThatCannotReadTheCatalogGetsAStateNotAnError(string $envVar): void
    {
        $admin = $this->admin($envVar);
        $parameters = TargetDatabase::parameters($envVar);
        $name = self::unique();
        $password = bin2hex(random_bytes(10));
        $user = $this->user($name, '%');
        $admin->executeStatement(\sprintf("CREATE USER %s IDENTIFIED BY '%s'", $user, $password));
        $admin->executeStatement(\sprintf('GRANT SELECT ON shop.* TO %s', $user));

        $reduced = $this->open($envVar, new ConnectionParameters($parameters->host, $parameters->port, $parameters->database, $name, $password));
        $status = $reduced->diagnostics()->accounts()->status();
        $report = $reduced->diagnostics()->accounts()->accounts();
        $reduced->close();

        self::assertSame(Availability::PermissionDenied, $status->availability);
        self::assertSame('SELECT ON mysql', $status->privilege);
        self::assertSame(Availability::PermissionDenied, $report->status->availability);
        self::assertSame([], $report->accounts);
        self::assertSame([], $report->findings());
    }

    #[DataProvider('targets')]
    public function testAnAccountThatOnlyReadsTheMysqlSchemaSeesTheOthersPrivileges(string $envVar): void
    {
        $admin = $this->admin($envVar);
        $parameters = TargetDatabase::parameters($envVar);
        $name = self::unique();
        $password = bin2hex(random_bytes(10));
        $reader = $this->user($name, '%');
        $other = $this->user($name.'_o', '%');
        $admin->executeStatement(\sprintf("CREATE USER %s IDENTIFIED BY '%s'", $reader, $password));
        $admin->executeStatement(\sprintf('GRANT SELECT ON mysql.* TO %s', $reader));
        $admin->executeStatement(\sprintf('GRANT SELECT ON shop.* TO %s', $reader));
        $admin->executeStatement(\sprintf("CREATE USER %s IDENTIFIED BY '%s'", $other, $password));
        $admin->executeStatement(\sprintf('GRANT PROCESS ON *.* TO %s', $other));
        $admin->executeStatement(\sprintf('GRANT SELECT ON shop.* TO %s', $other));

        $target = $this->open($envVar, new ConnectionParameters($parameters->host, $parameters->port, $parameters->database, $name, $password));
        $report = $target->diagnostics()->accounts()->accounts();
        $target->close();

        self::assertSame(Availability::Available, $report->status->availability);
        $seen = self::find($report->accounts, $name.'_o', '%');
        self::assertContains('PROCESS', $seen->globalPrivileges);
        self::assertContains('SELECT', $seen->databasePrivileges['shop']);
        self::assertTrue(self::find($report->accounts, $name, '%')->current);
    }
}
