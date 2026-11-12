<?php

declare(strict_types=1);

namespace App\Tests\Core\Diagnostics;

use App\Core\Connection\Engine;
use App\Core\Diagnostics\Account;
use App\Core\Diagnostics\AccountAuditor;
use App\Core\Diagnostics\AccountReport;
use App\Core\Diagnostics\AccountRule;
use App\Core\Diagnostics\DiagnosticStatus;
use App\Core\Diagnostics\SecurityFinding;
use App\Core\Diagnostics\Severity;
use App\Core\Diagnostics\TlsRequirement;
use PHPUnit\Framework\TestCase;

final class AccountAuditorTest extends TestCase
{
    /**
     * @param list<Account> $accounts
     * @param list<string>  $disabledPlugins
     *
     * @return list<SecurityFinding>
     */
    private function audit(array $accounts, Engine $engine = Engine::MySql, bool $activateAll = false, array $disabledPlugins = []): array
    {
        return (new AccountAuditor())->audit(new AccountReport(DiagnosticStatus::available(), $engine, $accounts, false, $activateAll, [], $disabledPlugins));
    }

    /**
     * @param array<string, mixed> $with
     */
    private static function account(string $user = 'app', string $host = 'localhost', array $with = []): Account
    {
        // @phpstan-ignore argument.type
        return new Account(...($with + ['user' => $user, 'host' => $host, 'plugin' => 'caching_sha2_password', 'passwordSet' => true]));
    }

    /**
     * @param list<SecurityFinding> $findings
     *
     * @return list<string>
     */
    private static function rules(array $findings, ?string $account = null): array
    {
        $rules = [];
        foreach ($findings as $f) {
            if (null === $account || $account === $f->account) {
                $rules[] = $f->rule->value;
            }
        }
        sort($rules);

        return $rules;
    }

    public function testACleanLocalAccountHasNothingToReport(): void
    {
        self::assertSame([], $this->audit([self::account(with: ['globalPrivileges' => ['USAGE'], 'databasePrivileges' => ['shop' => ['SELECT']]])]));
    }

    public function testAnAnonymousAccountIsHigh(): void
    {
        $findings = $this->audit([self::account('', 'localhost')]);

        self::assertSame(['anonymous_account'], self::rules($findings));
        self::assertSame(Severity::High, $findings[0]->severity);
    }

    public function testAWildcardHostIsReportedButNotAPartialPattern(): void
    {
        self::assertSame(['wildcard_host'], self::rules($this->audit([self::account('app', '%', ['tls' => TlsRequirement::Any])])));
        self::assertSame([], self::rules($this->audit([self::account('app', '10.0.0.%', ['tls' => TlsRequirement::Any])])));
    }

    public function testTheSameNameOnSeveralHostsIsReportedOnceWithTheHosts(): void
    {
        $findings = $this->audit([self::account('web', 'localhost'), self::account('web', '127.0.0.1'), self::account('db', 'localhost')]);

        self::assertCount(1, $findings);
        self::assertSame(AccountRule::SameNameManyHosts, $findings[0]->rule);
        self::assertSame('web', $findings[0]->account);
        self::assertSame('127.0.0.1, localhost', $findings[0]->detail);
    }

    public function testAnAccountWithoutPasswordIsHighUnlessItsPluginDoesNotUseOne(): void
    {
        self::assertSame(['no_password'], self::rules($this->audit([self::account(with: ['passwordSet' => false])])));
        self::assertSame([], $this->audit([self::account(with: ['passwordSet' => false, 'plugin' => 'auth_socket'])]));
        self::assertSame([], $this->audit([self::account(with: ['passwordSet' => false, 'plugin' => 'unix_socket'])]));
    }

    public function testLegacyPluginsDependOnTheEngine(): void
    {
        $native = self::account(with: ['plugin' => 'mysql_native_password']);

        self::assertSame(['legacy_plugin'], self::rules($this->audit([$native], Engine::MySql)));
        self::assertSame([], $this->audit([$native], Engine::MariaDb));
        $old = $this->audit([self::account(with: ['plugin' => 'mysql_old_password'])], Engine::MariaDb);
        self::assertSame(['legacy_plugin'], self::rules($old));
        self::assertSame(Severity::High, $old[0]->severity);
    }

    public function testADisabledPluginMeansTheAccountCannotConnect(): void
    {
        $findings = $this->audit([self::account(with: ['plugin' => 'mysql_native_password'])], Engine::MySql, false, ['mysql_native_password']);

        self::assertContains('disabled_plugin', self::rules($findings));
    }

    public function testAnExpiredPasswordIsReported(): void
    {
        self::assertSame(['password_expired'], self::rules($this->audit([self::account(with: ['passwordExpired' => true])])));
    }

    public function testLockedAccountsAndRolesAreLeftAlone(): void
    {
        self::assertSame([], $this->audit([self::account('', '%', ['locked' => true, 'passwordSet' => false])]));
        self::assertSame([], $this->audit([self::account('reader', '%', ['isRole' => true, 'passwordSet' => false, 'globalPrivileges' => ['FILE']])]));
    }

    public function testDangerousGlobalPrivilegesAreNamed(): void
    {
        $findings = $this->audit([self::account(with: ['globalPrivileges' => ['SELECT', 'FILE', 'SUPER', 'PROCESS']])]);

        self::assertSame(['dangerous_privilege', 'dangerous_privilege', 'dangerous_privilege'], self::rules($findings));
        self::assertSame(['FILE', 'PROCESS', 'SUPER'], array_map(static fn (SecurityFinding $f): ?string => $f->detail, $findings));
    }

    public function testEveryDataAndStructurePrivilegeOnEverythingIsAllPrivileges(): void
    {
        $all = ['SELECT', 'INSERT', 'UPDATE', 'DELETE', 'CREATE', 'DROP', 'ALTER'];

        self::assertSame(['all_privileges'], self::rules($this->audit([self::account(with: ['globalPrivileges' => $all])])));
        self::assertSame([], $this->audit([self::account(with: ['globalPrivileges' => ['SELECT', 'INSERT']])]));
    }

    public function testAPrivilegeReceivedThroughANestedRoleIsFoundWithTheClosure(): void
    {
        $accounts = [
            self::account('app', 'localhost', ['roles' => ['top'], 'defaultRoles' => ['top']]),
            self::account('top', '', ['isRole' => true, 'roles' => ['mid']]),
            self::account('mid', '', ['isRole' => true, 'roles' => ['top'], 'globalPrivileges' => ['FILE']]),
        ];

        $findings = $this->audit($accounts, Engine::MariaDb);

        self::assertSame(['dangerous_privilege'], self::rules($findings));
        self::assertSame('FILE (via mid)', $findings[0]->detail);
        self::assertSame('app@localhost', $findings[0]->account);
    }

    public function testGrantOptionNamesTheScopes(): void
    {
        $findings = $this->audit([self::account(with: ['grantOptionOn' => ['*.*', 'shop']])]);

        self::assertSame(['grant_option'], self::rules($findings));
        self::assertSame('*.*, shop', $findings[0]->detail);
    }

    public function testAGrantedRoleThatIsNeverActivatedGivesNoRights(): void
    {
        $roles = [self::account('reader', '%', ['isRole' => true, 'globalPrivileges' => ['SELECT']])];
        $inactive = self::account('app', 'localhost', ['roles' => ['reader']]);
        $active = self::account('app2', 'localhost', ['roles' => ['reader'], 'defaultRoles' => ['reader']]);

        self::assertSame(['role_never_active'], self::rules($this->audit([$inactive, ...$roles])));
        self::assertSame([], $this->audit([$active, ...$roles]));
        self::assertSame([], $this->audit([$inactive, ...$roles], Engine::MySql, true));
    }

    public function testNoTlsRequirementOnlyMattersForAccountsThatCanComeFromTheNetwork(): void
    {
        self::assertSame([], $this->audit([self::account('app', 'localhost')]));
        self::assertSame(['no_tls_requirement'], self::rules($this->audit([self::account('app', '10.0.0.5')])));
        self::assertSame([], $this->audit([self::account('app', '10.0.0.5', ['tls' => TlsRequirement::X509])]));
    }

    public function testTheOpenTestDatabaseOfAnonymousAccountsIsReported(): void
    {
        $findings = $this->audit([self::account('', 'localhost', ['databasePrivileges' => ['test' => ['SELECT'], 'test\_%' => ['ALL PRIVILEGES']]])], Engine::MariaDb);

        self::assertContains('open_test_database', self::rules($findings));
    }

    public function testTheAccountOfTheConnectionIsMarkedAndItsFindingsAreHigh(): void
    {
        $findings = $this->audit([self::account(with: ['current' => true, 'globalPrivileges' => ['FILE']])]);

        self::assertTrue($findings[0]->currentAccount);
        self::assertSame(Severity::High, $findings[0]->severity);
    }

    public function testFindingsAreOrderedBySeverityThenAccount(): void
    {
        $findings = $this->audit([
            self::account('b', 'localhost', ['passwordExpired' => true]),
            self::account('a', 'localhost', ['passwordSet' => false]),
            self::account('c', 'localhost', ['plugin' => 'mysql_native_password']),
        ]);

        self::assertSame(['no_password', 'password_expired', 'legacy_plugin'], array_map(static fn (SecurityFinding $f): string => $f->rule->value, $findings));
    }

    public function testAnUnreadableReportHasNoFinding(): void
    {
        self::assertSame([], (new AccountAuditor())->audit(new AccountReport(DiagnosticStatus::denied('SELECT ON mysql'), Engine::MySql, [], false)));
    }

    public function testACutListIsSaidToBeIncompleteInsteadOfClean(): void
    {
        $cutPrivileges = (new AccountAuditor())->audit(new AccountReport(DiagnosticStatus::available(), Engine::MySql, [self::account()], false, privilegesIncomplete: true));
        $cutAccounts = (new AccountAuditor())->audit(new AccountReport(DiagnosticStatus::available(), Engine::MySql, [self::account()], true));

        self::assertSame(['incomplete_check'], self::rules($cutPrivileges));
        self::assertSame('privileges', $cutPrivileges[0]->detail);
        self::assertSame(Severity::Medium, $cutPrivileges[0]->severity);
        self::assertNull($cutPrivileges[0]->account);
        self::assertSame(['incomplete_check'], self::rules($cutAccounts));
        self::assertSame('accounts', $cutAccounts[0]->detail);
    }
}
