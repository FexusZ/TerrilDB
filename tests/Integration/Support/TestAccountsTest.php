<?php

declare(strict_types=1);

namespace App\Tests\Integration\Support;

use App\Core\Connection\Engine;
use App\Tests\Integration\Driver\LockPeer;
use App\Tests\Integration\Driver\TargetDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Preuve des comptes de test jetables : chaque profil a bien les droits annoncés, et rien ne reste une fois supprimés, même quand
 * le test échoue au milieu.
 */
#[Group('integration')]
final class TestAccountsTest extends TestCase
{
    private string $env = '';
    private string $table = '';

    /** @return iterable<string, array{string}> */
    public static function engines(): iterable
    {
        yield from TargetDatabase::relational();
    }

    /** @return iterable<string, array{string}> */
    public static function mysqlFamily(): iterable
    {
        foreach (TargetDatabase::relational() as $label => [$env]) {
            if (Engine::PostgreSql !== TargetDatabase::engine($env)) {
                yield $label => [$env];
            }
        }
    }

    protected function tearDown(): void
    {
        if ('' !== $this->table) {
            LockPeer::connect($this->env)->exec(\sprintf('DROP TABLE IF EXISTS %s', $this->table));
        }
        if ('' !== $this->env) {
            TestAccounts::dropLeftovers($this->env);
        }
    }

    #[DataProvider('engines')]
    public function testReadOnlyAccountReadsButNeverWrites(string $env): void
    {
        $this->seed($env);

        TestAccounts::using($env, function (TestAccounts $accounts): void {
            $account = $accounts->readOnly($this->table);
            $pdo = $account->connect();
            $t = $account->qualify($this->table);

            self::assertSame('1', BlockingSession::scalar($pdo, \sprintf('SELECT COUNT(*) FROM %s', $t)));
            self::assertDenied($pdo, \sprintf('INSERT INTO %s VALUES (9, 9)', $t));
            self::assertDenied($pdo, \sprintf('UPDATE %s SET v = 2', $t));
            self::assertDenied($pdo, \sprintf('DELETE FROM %s', $t));
        });
    }

    #[DataProvider('engines')]
    public function testApplicationAccountHasNoDeleteAndNoDdl(string $env): void
    {
        $this->seed($env);

        TestAccounts::using($env, function (TestAccounts $accounts): void {
            $account = $accounts->application($this->table);
            $pdo = $account->connect();
            $t = $account->qualify($this->table);

            $pdo->exec(\sprintf('INSERT INTO %s VALUES (2, 2)', $t));
            $pdo->exec(\sprintf('UPDATE %s SET v = 3 WHERE id = 2', $t));
            self::assertDenied($pdo, \sprintf('DELETE FROM %s WHERE id = 2', $t));
            self::assertDenied($pdo, \sprintf('ALTER TABLE %s ADD COLUMN extra INT', $t));
            self::assertDenied($pdo, \sprintf('DROP TABLE %s', $t));
            self::assertDenied($pdo, 'CREATE TABLE '.$t.'_other (id INT)');
        });
    }

    #[DataProvider('engines')]
    public function testGrantedRoleIsNotActiveUntilRequested(string $env): void
    {
        $this->seed($env);

        TestAccounts::using($env, function (TestAccounts $accounts): void {
            $account = $accounts->withInactiveRole($this->table);
            $pdo = $account->connect();
            $t = $account->qualify($this->table);
            $select = \sprintf('SELECT COUNT(*) FROM %s', $t);

            self::assertDenied($pdo, $select);
            $role = $accounts->roleName();
            $pdo->exec(\sprintf('SET ROLE %s', $role));
            self::assertSame('1', BlockingSession::scalar($pdo, $select));
        });
    }

    #[DataProvider('mysqlFamily')]
    public function testAccountsExistForAnyHostOrLocalhostOnly(string $env): void
    {
        $this->seed($env);

        TestAccounts::using($env, function (TestAccounts $accounts) use ($env): void {
            $anyHost = $accounts->readOnly($this->table, '%');
            $local = $accounts->readOnly($this->table, 'localhost');

            self::assertSame('%', $anyHost->host);
            self::assertSame('localhost', $local->host);
            self::assertNotSame($anyHost->name, $local->name);
            $hosts = LockPeer::connect($env)->query(\sprintf("SELECT host FROM mysql.user WHERE user IN ('%s', '%s') ORDER BY host", $anyHost->name, $local->name));
            self::assertNotFalse($hosts);
            self::assertSame(['%', 'localhost'], $hosts->fetchAll(\PDO::FETCH_COLUMN));
            $anyHost->connect();
        });
    }

    #[DataProvider('engines')]
    public function testAccountWithoutPasswordHasNone(string $env): void
    {
        TestAccounts::using($env, static function (TestAccounts $accounts) use ($env): void {
            $account = $accounts->withoutPassword();

            self::assertSame('', $account->password);
            if (Engine::PostgreSql === TargetDatabase::engine($env)) {
                self::assertSame('1', BlockingSession::scalar(LockPeer::connect($env), \sprintf("SELECT COUNT(*) FROM pg_authid WHERE rolname = '%s' AND rolpassword IS NULL AND rolcanlogin", $account->name)));

                return;
            }
            self::assertSame($account->name, explode('@', BlockingSession::scalar($account->connect(), 'SELECT CURRENT_USER()'))[0]);
        });
    }

    public function testRoleCreatorCanCreateRolesOnPostgreSql(): void
    {
        $this->seed('TARGET_POSTGRES_URL');

        TestAccounts::using($this->env, static function (TestAccounts $accounts): void {
            $account = $accounts->withCreateRole();
            $pdo = $account->connect();
            $child = $account->name.'_child';
            try {
                $pdo->exec(\sprintf('CREATE ROLE %s', $child));
                self::assertSame('1', BlockingSession::scalar($pdo, \sprintf("SELECT COUNT(*) FROM pg_roles WHERE rolname = '%s'", $child)));
            } finally {
                $pdo->exec(\sprintf('DROP ROLE IF EXISTS %s', $child));
            }
        });
    }

    #[DataProvider('engines')]
    public function testLimitedAccountConnectsButSeesNothingPrivileged(string $env): void
    {
        $this->seed($env);

        TestAccounts::using($env, function (TestAccounts $accounts) use ($env): void {
            $account = $accounts->limited();
            $pdo = $account->connect();
            $t = $account->qualify($this->table);

            self::assertDenied($pdo, \sprintf('SELECT COUNT(*) FROM %s', $t));
            self::assertDenied($pdo, Engine::PostgreSql === TargetDatabase::engine($env) ? 'SELECT * FROM pg_authid' : 'SELECT * FROM mysql.user');
        });
    }

    #[DataProvider('engines')]
    public function testEverythingIsDroppedAfterUse(string $env): void
    {
        $this->seed($env);

        TestAccounts::using($env, function (TestAccounts $accounts) use ($env): void {
            $accounts->readOnly($this->table);
            $accounts->application($this->table);
            $accounts->withInactiveRole($this->table);
            $accounts->withoutPassword();
            $accounts->limited();
            self::assertGreaterThanOrEqual(6, \count(TestAccounts::remaining($env)), 'Users and the role must exist while in use.');
        });

        self::assertSame([], TestAccounts::remaining($env));
    }

    #[DataProvider('engines')]
    public function testCleanupHappensEvenWhenTheTestBodyThrows(string $env): void
    {
        $this->seed($env);
        $caught = '';

        try {
            TestAccounts::using($env, function (TestAccounts $accounts): void {
                $accounts->readOnly($this->table);
                throw new \RuntimeException('boom');
            });
        } catch (\RuntimeException $e) {
            $caught = $e->getMessage();
        }

        self::assertSame('boom', $caught);
        self::assertSame([], TestAccounts::remaining($env));
    }

    #[DataProvider('engines')]
    public function testDropLeftoversSweepsAccountsOfACrashedRun(string $env): void
    {
        $this->seed($env);
        $accounts = TestAccounts::forTarget($env);
        $accounts->readOnly($this->table);
        $accounts->withInactiveRole($this->table);
        self::assertNotSame([], TestAccounts::remaining($env));

        // Un autre processus (ou un run interrompu) n'a plus la liste : le balayage retrouve les comptes par leur préfixe.
        TestAccounts::dropLeftovers($env);

        self::assertSame([], TestAccounts::remaining($env));
    }

    public function testNamesAreUnique(): void
    {
        $this->seed('TARGET_MYSQL_URL');

        TestAccounts::using($this->env, static function (TestAccounts $accounts): void {
            $names = [];
            for ($i = 0; $i < 5; ++$i) {
                $names[] = $accounts->limited()->name;
            }
            self::assertCount(5, array_unique($names));
        });
    }

    private function seed(string $env): void
    {
        $this->env = $env;
        $this->table = 'tdb_h_'.bin2hex(random_bytes(4));
        $pdo = LockPeer::connect($env);
        $pdo->exec(\sprintf('CREATE TABLE %s (id INT PRIMARY KEY, v INT NOT NULL)', $this->table));
        $pdo->exec(\sprintf('INSERT INTO %s VALUES (1, 1)', $this->table));
    }

    private static function assertDenied(\PDO $pdo, string $sql): void
    {
        try {
            $pdo->exec($sql);
        } catch (\PDOException $e) {
            // 42501 PostgreSQL ; 1044/1142/1143/1227/1045 côté MySQL (HY000 ou 42000).
            self::assertMatchesRegularExpression('/^(42501|42000|HY000)$/', (string) $e->getCode(), $e->getMessage());

            return;
        }
        self::fail('The statement must be refused: '.$sql);
    }
}
