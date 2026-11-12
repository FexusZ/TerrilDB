<?php

declare(strict_types=1);

namespace App\Core\Diagnostics;

use App\Core\Connection\Engine;

/**
 * Les règles d'audit de sécurité des comptes, pures : elles ne lisent que le rapport. Les comptes verrouillés et les rôles (qui ne
 * s'ouvrent pas) n'ont pas de constatation propre ; ce qu'un rôle donne se retrouve chez les comptes qui le reçoivent.
 */
final class AccountAuditor
{
    private const DANGEROUS = ['FILE', 'PROCESS', 'SUPER', 'SHUTDOWN', 'CREATE USER'];
    private const FULL_SET = ['SELECT', 'INSERT', 'UPDATE', 'DELETE', 'CREATE', 'DROP', 'ALTER'];
    private const NO_PASSWORD_PLUGINS = ['mysql_native_password', 'mysql_old_password', 'caching_sha2_password', 'sha256_password', 'auth_ed25519', 'ed25519', 'parsec'];
    private const LOCAL_HOSTS = ['localhost', '::1'];

    /**
     * @return list<SecurityFinding>
     */
    public function audit(AccountReport $report): array
    {
        if (!$report->status->isAvailable()) {
            return [];
        }

        $byName = [];
        foreach ($report->accounts as $account) {
            $byName[$account->name()] = $account;
        }

        $findings = [];
        $hosts = [];
        foreach ($report->accounts as $account) {
            if ($account->isRole || $account->locked) {
                continue;
            }
            if ('' !== $account->user) {
                $hosts[$account->user][] = $account->host;
            }
            array_push($findings, ...$this->account($account, $report, $byName));
        }
        foreach ($hosts as $user => $list) {
            $list = array_values(array_unique($list));
            if (\count($list) > 1) {
                sort($list);
                $findings[] = new SecurityFinding(AccountRule::SameNameManyHosts, Severity::Low, (string) $user, implode(', ', $list), false);
            }
        }

        usort($findings, static fn (SecurityFinding $a, SecurityFinding $b): int => [$a->severity->rank(), $a->account ?? '', $a->rule->value, $a->detail ?? ''] <=> [$b->severity->rank(), $b->account ?? '', $b->rule->value, $b->detail ?? '']);

        return $findings;
    }

    /**
     * @param array<string, Account> $byName
     *
     * @return list<SecurityFinding>
     */
    private function account(Account $account, AccountReport $report, array $byName): array
    {
        $name = $account->name();
        $make = static fn (AccountRule $rule, Severity $severity, ?string $detail = null): SecurityFinding => new SecurityFinding($rule, $account->current && Severity::Low !== $severity && \in_array($rule, [AccountRule::DangerousPrivilege, AccountRule::AllPrivileges, AccountRule::GrantOption], true) ? Severity::High : $severity, $name, $detail, $account->current);
        $out = [];

        if ('' === $account->user) {
            $out[] = $make(AccountRule::AnonymousAccount, Severity::High);
            foreach (array_keys($account->databasePrivileges) as $schema) {
                if (1 === preg_match('/^test(\\\\?_%)?$/D', (string) $schema)) {
                    $out[] = $make(AccountRule::OpenTestDatabase, Severity::Medium, (string) $schema);
                }
            }
        }
        if ('%' === $account->host) {
            $out[] = $make(AccountRule::WildcardHost, Severity::Medium);
        }
        if (!$account->passwordSet && \in_array($account->plugin, self::NO_PASSWORD_PLUGINS, true)) {
            $out[] = $make(AccountRule::NoPassword, Severity::High);
        }
        if ('mysql_old_password' === $account->plugin) {
            $out[] = $make(AccountRule::LegacyPlugin, Severity::High, $account->plugin);
        } elseif (Engine::MySql === $report->engine && \in_array($account->plugin, ['mysql_native_password', 'sha256_password'], true)) {
            $out[] = $make(AccountRule::LegacyPlugin, Severity::Low, $account->plugin);
        }
        if (\in_array($account->plugin, $report->disabledPlugins, true)) {
            $out[] = $make(AccountRule::DisabledPlugin, Severity::Medium, $account->plugin);
        }
        if ($account->passwordExpired) {
            $out[] = $make(AccountRule::PasswordExpired, Severity::Medium);
        }
        if (TlsRequirement::None === $account->tls && !self::isLocal($account->host)) {
            $out[] = $make(AccountRule::NoTlsRequirement, Severity::Low);
        }

        $reachable = RoleClosure::reachable($account->roles, $byName);
        $sources = ['' => $account->globalPrivileges];
        foreach ($reachable as $role) {
            $sources[$role] = $byName[$role]->globalPrivileges;
        }
        $seen = [];
        foreach ($sources as $via => $privileges) {
            $upper = array_map('strtoupper', $privileges);
            foreach (self::DANGEROUS as $dangerous) {
                if (\in_array($dangerous, $upper, true) && !isset($seen[$dangerous])) {
                    $seen[$dangerous] = true;
                    $out[] = $make(AccountRule::DangerousPrivilege, Severity::Medium, '' === (string) $via ? $dangerous : $dangerous.' (via '.$via.')');
                }
            }
            if (!isset($seen['*all']) && [] === array_diff(self::FULL_SET, $upper)) {
                $seen['*all'] = true;
                $out[] = $make(AccountRule::AllPrivileges, Severity::Medium, '' === (string) $via ? null : 'via '.$via);
            }
        }

        $grantable = $account->grantOptionOn;
        foreach ($reachable as $role) {
            array_push($grantable, ...$byName[$role]->grantOptionOn);
        }
        $grantable = array_values(array_unique($grantable));
        if ([] !== $grantable) {
            $out[] = $make(AccountRule::GrantOption, Severity::Medium, implode(', ', $grantable));
        }

        if ([] !== $account->roles && !$report->activatesAllRoles) {
            $active = array_merge($account->defaultRoles, RoleClosure::reachable($account->defaultRoles, $byName));
            $idle = array_values(array_diff($account->roles, $active));
            if ([] !== $idle) {
                $out[] = $make(AccountRule::RoleNeverActive, Severity::Low, implode(', ', $idle));
            }
        }

        return $out;
    }

    private static function isLocal(string $host): bool
    {
        return \in_array(strtolower($host), self::LOCAL_HOSTS, true) || str_starts_with($host, '127.');
    }
}
