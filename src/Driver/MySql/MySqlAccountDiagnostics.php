<?php

declare(strict_types=1);

namespace App\Driver\MySql;

use App\Core\Connection\Engine;
use App\Core\Diagnostics\Account;
use App\Core\Diagnostics\AccountDiagnostics;
use App\Core\Diagnostics\AccountReport;
use App\Core\Diagnostics\DiagnosticStatus;
use App\Core\Diagnostics\TlsRequirement;
use App\Driver\DbalCatalogReader;
use App\Driver\RowReader;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\DriverException;

/**
 * Comptes, rôles et privilèges de MySQL et de MariaDB, en lecture seule.
 *
 * MySQL : mysql.user, mysql.role_edges, mysql.default_roles. MariaDB : mysql.global_priv (JSON depuis 10.4 ; `mysql.user` n'est
 * qu'une vue sans l'état verrouillé), mysql.roles_mapping. Les droits viennent d'information_schema (USER_PRIVILEGES,
 * SCHEMA_PRIVILEGES), identiques dans les deux. JAMAIS une empreinte de mot de passe : seul un booléen « secret vide » est calculé
 * par le serveur (`authentication_string = ''`), la colonne elle-même n'est pas sélectionnée.
 */
final readonly class MySqlAccountDiagnostics implements AccountDiagnostics
{
    private const LIMIT = 300;
    private const PRIVILEGE_ROWS = 20000;
    private const REFUSALS = [1044, 1142, 1143, 1227];
    private const IDENTITY = "CONCAT('''', User, '''@''', Host, '''')";

    private DbalCatalogReader $catalog;

    public function __construct(Connection $connection, private Engine $engine)
    {
        $this->catalog = DbalCatalogReader::mysql($connection);
    }

    public function status(): DiagnosticStatus
    {
        return $this->catalog->read(fn (Connection $c): DiagnosticStatus => $this->check($c));
    }

    public function accounts(): AccountReport
    {
        return $this->catalog->read(function (Connection $c): AccountReport {
            $status = $this->check($c);
            if (!$status->isAvailable()) {
                return new AccountReport($status, $this->engine, [], false);
            }

            try {
                return $this->read($c);
            } catch (DriverException $e) {
                // Un droit retiré entre la vérification et la lecture : un état, pas une panne.
                if (\in_array($e->getCode(), self::REFUSALS, true)) {
                    return new AccountReport(self::denied(), $this->engine, [], false);
                }

                throw $e;
            }
        });
    }

    private static function denied(): DiagnosticStatus
    {
        return DiagnosticStatus::denied('SELECT ON mysql');
    }

    private function check(Connection $connection): DiagnosticStatus
    {
        $tables = Engine::MariaDb === $this->engine ? ['mysql.global_priv', 'mysql.roles_mapping'] : ['mysql.user', 'mysql.role_edges', 'mysql.default_roles'];
        foreach ($tables as $table) {
            try {
                $connection->fetchOne(\sprintf('SELECT 1 FROM %s LIMIT 1', $table));
            } catch (DriverException $e) {
                if (\in_array($e->getCode(), self::REFUSALS, true)) {
                    return self::denied();
                }

                throw $e;
            }
        }

        return DiagnosticStatus::available();
    }

    private function read(Connection $c): AccountReport
    {
        $mariadb = Engine::MariaDb === $this->engine;
        $rows = $mariadb ? $this->mariaDbAccounts($c) : $this->mySqlAccounts($c);
        $truncated = \count($rows) > self::LIMIT;
        $rows = \array_slice($rows, 0, self::LIMIT);

        [$roles, $defaults, $isRole] = $mariadb ? $this->mariaDbRoles($c) : $this->mySqlRoles($c);
        $global = [];
        $grantable = [];
        foreach ($c->fetchAllAssociative("SELECT GRANTEE AS g, PRIVILEGE_TYPE AS p, IS_GRANTABLE AS gr FROM information_schema.USER_PRIVILEGES WHERE PRIVILEGE_TYPE <> 'USAGE' ORDER BY GRANTEE, PRIVILEGE_TYPE LIMIT ".self::PRIVILEGE_ROWS) as $row) {
            $row = new RowReader($row);
            $global[$row->string('g')][] = strtoupper($row->string('p'));
            if ($row->bool('gr')) {
                $grantable[$row->string('g')]['*.*'] = true;
            }
        }
        $schemas = [];
        foreach ($c->fetchAllAssociative("SELECT GRANTEE AS g, TABLE_SCHEMA AS s, PRIVILEGE_TYPE AS p, IS_GRANTABLE AS gr FROM information_schema.SCHEMA_PRIVILEGES WHERE PRIVILEGE_TYPE <> 'USAGE' ORDER BY GRANTEE, TABLE_SCHEMA, PRIVILEGE_TYPE LIMIT ".self::PRIVILEGE_ROWS) as $row) {
            $row = new RowReader($row);
            $schemas[$row->string('g')][$row->string('s')][] = strtoupper($row->string('p'));
            if ($row->bool('gr')) {
                $grantable[$row->string('g')][$row->string('s')] = true;
            }
        }

        $accounts = [];
        $plugins = [];
        foreach ($rows as $row) {
            $row = new RowReader($row);
            $name = '' === $row->string('host') ? $row->string('usr') : $row->string('usr').'@'.$row->string('host');
            $grantee = $row->string('grantee');
            $plugins[$row->string('plugin')] = true;
            $accounts[] = new Account(
                user: $row->string('usr'),
                host: $row->string('host'),
                plugin: $row->string('plugin'),
                passwordSet: !$row->bool('no_secret'),
                passwordExpired: $row->bool('expired'),
                locked: $row->bool('locked'),
                tls: self::tls($row->string('tls')),
                isRole: $mariadb ? $row->bool('is_role') : isset($isRole[$name]),
                current: $row->bool('current_account'),
                roles: $roles[$name] ?? [],
                defaultRoles: $defaults[$name] ?? [],
                globalPrivileges: array_values(array_unique($global[$grantee] ?? [])),
                databasePrivileges: array_map(static fn (array $p): array => array_values(array_unique($p)), $schemas[$grantee] ?? []),
                grantOptionOn: array_keys($grantable[$grantee] ?? []),
                limits: array_filter([
                    'max_questions' => $row->int('max_questions'),
                    'max_updates' => $row->int('max_updates'),
                    'max_connections' => $row->int('max_connections'),
                    'max_user_connections' => $row->int('max_user_connections'),
                ]),
            );
        }

        $activateAll = false;
        $mandatory = [];
        if (!$mariadb) {
            $activateAll = 1 === (new RowReader(['v' => $c->fetchOne('SELECT @@activate_all_roles_on_login')]))->int('v');
            $mandatory = array_values(array_filter(array_map('trim', explode(',', (new RowReader(['v' => $c->fetchOne('SELECT @@mandatory_roles')]))->nullableString('v') ?? ''))));
        }

        return new AccountReport(DiagnosticStatus::available(), $this->engine, $accounts, $truncated, $activateAll, $mandatory, $this->disabledPlugins($c, array_keys($plugins)));
    }

    /**
     * @param list<string|int> $used
     *
     * @return list<string>
     */
    private function disabledPlugins(Connection $c, array $used): array
    {
        $active = [];
        foreach ($c->fetchAllAssociative("SELECT PLUGIN_NAME AS n, PLUGIN_STATUS AS s FROM information_schema.PLUGINS WHERE PLUGIN_TYPE = 'AUTHENTICATION'") as $row) {
            $row = new RowReader($row);
            if ('ACTIVE' === strtoupper($row->string('s'))) {
                $active[$row->string('n')] = true;
            }
        }

        return array_values(array_filter(array_map('strval', $used), static fn (string $plugin): bool => '' !== $plugin && !isset($active[$plugin])));
    }

    private static function tls(string $value): TlsRequirement
    {
        return match (strtoupper($value)) {
            'ANY', '1' => TlsRequirement::Any,
            'X509', '2' => TlsRequirement::X509,
            'SPECIFIED', '3' => TlsRequirement::Specified,
            default => TlsRequirement::None,
        };
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function mySqlAccounts(Connection $c): array
    {
        return $c->fetchAllAssociative(
            'SELECT User AS usr, Host AS host, '.self::IDENTITY." AS grantee, plugin, authentication_string = '' AS no_secret,
                    COALESCE(password_expired = 'Y' OR (COALESCE(password_lifetime, @@default_password_lifetime) > 0 AND password_last_changed + INTERVAL COALESCE(password_lifetime, @@default_password_lifetime) DAY < NOW()), 0) AS expired, account_locked = 'Y' AS locked, ssl_type AS tls,
                    max_questions, max_updates, max_connections, max_user_connections,
                    CONCAT(User, '@', Host) = CURRENT_USER() AS current_account
               FROM mysql.user
              ORDER BY User, Host
              LIMIT ".(self::LIMIT + 1),
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function mariaDbAccounts(Connection $c): array
    {
        return $c->fetchAllAssociative(
            'SELECT User AS usr, Host AS host, '.self::IDENTITY." AS grantee,
                    COALESCE(JSON_VALUE(Priv, '\$.plugin'), '') AS plugin,
                    COALESCE(JSON_VALUE(Priv, '\$.authentication_string'), '') = '' AS no_secret,
                    COALESCE(COALESCE(JSON_VALUE(Priv, '\$.password_expired'), '0') IN ('1', 'true')
                     OR JSON_VALUE(Priv, '\$.password_last_changed') = '0'
                     OR (JSON_VALUE(Priv, '\$.password_last_changed') > 0
                         AND CASE WHEN COALESCE(JSON_VALUE(Priv, '\$.password_lifetime'), '-1') = '-1' THEN @@default_password_lifetime ELSE JSON_VALUE(Priv, '\$.password_lifetime') END > 0
                         AND JSON_VALUE(Priv, '\$.password_last_changed') + 86400 * CASE WHEN COALESCE(JSON_VALUE(Priv, '\$.password_lifetime'), '-1') = '-1' THEN @@default_password_lifetime ELSE JSON_VALUE(Priv, '\$.password_lifetime') END < UNIX_TIMESTAMP()), 0) AS expired,
                    COALESCE(JSON_VALUE(Priv, '\$.account_locked'), '0') IN ('1', 'true') AS locked,
                    COALESCE(JSON_VALUE(Priv, '\$.is_role'), '0') IN ('1', 'true') AS is_role,
                    COALESCE(JSON_VALUE(Priv, '\$.ssl_type'), '0') AS tls,
                    COALESCE(JSON_VALUE(Priv, '\$.max_questions'), 0) AS max_questions,
                    COALESCE(JSON_VALUE(Priv, '\$.max_updates'), 0) AS max_updates,
                    COALESCE(JSON_VALUE(Priv, '\$.max_connections'), 0) AS max_connections,
                    COALESCE(JSON_VALUE(Priv, '\$.max_user_connections'), 0) AS max_user_connections,
                    CONCAT(User, '@', Host) = CURRENT_USER() AS current_account
               FROM mysql.global_priv
              ORDER BY User, Host
              LIMIT ".(self::LIMIT + 1),
        );
    }

    /**
     * @return array{array<string, list<string>>, array<string, list<string>>, array<string, true>}
     */
    private function mySqlRoles(Connection $c): array
    {
        $roles = [];
        $isRole = [];
        foreach ($c->fetchAllAssociative("SELECT CONCAT(FROM_USER, '@', FROM_HOST) AS role, CONCAT(TO_USER, '@', TO_HOST) AS holder FROM mysql.role_edges ORDER BY 1, 2") as $row) {
            $row = new RowReader($row);
            $roles[$row->string('holder')][] = $row->string('role');
            $isRole[$row->string('role')] = true;
        }
        $defaults = [];
        foreach ($c->fetchAllAssociative("SELECT CONCAT(USER, '@', HOST) AS holder, CONCAT(DEFAULT_ROLE_USER, '@', DEFAULT_ROLE_HOST) AS role FROM mysql.default_roles ORDER BY 1, 2") as $row) {
            $row = new RowReader($row);
            $defaults[$row->string('holder')][] = $row->string('role');
        }

        return [$roles, $defaults, $isRole];
    }

    /**
     * @return array{array<string, list<string>>, array<string, list<string>>, array<string, true>}
     */
    private function mariaDbRoles(Connection $c): array
    {
        $roles = [];
        foreach ($c->fetchAllAssociative("SELECT IF(Host = '', User, CONCAT(User, '@', Host)) AS holder, Role AS role FROM mysql.roles_mapping ORDER BY 1, 2") as $row) {
            $row = new RowReader($row);
            $roles[$row->string('holder')][] = $row->string('role');
        }
        $defaults = [];
        foreach ($c->fetchAllAssociative("SELECT IF(Host = '', User, CONCAT(User, '@', Host)) AS holder, JSON_VALUE(Priv, '\$.default_role') AS role FROM mysql.global_priv WHERE JSON_VALUE(Priv, '\$.default_role') IS NOT NULL AND JSON_VALUE(Priv, '\$.default_role') <> ''") as $row) {
            $row = new RowReader($row);
            $defaults[$row->string('holder')][] = $row->string('role');
        }

        return [$roles, $defaults, []];
    }
}
