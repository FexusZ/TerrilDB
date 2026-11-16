<?php

declare(strict_types=1);

namespace App\Tests\Integration\Support;

use App\Core\Connection\Engine;
use App\Tests\Integration\Driver\LockPeer;
use App\Tests\Integration\Driver\TargetDatabase;

/**
 * Comptes de base de données jetables pour les tests d'intégration. Rien n'annule un CREATE USER (ni la transaction d'un test, ni
 * DAMA) : les comptes sont donc créés et supprimés explicitement, avec un nom unique à préfixe `tdb_t_`. Passer par `using()`
 * (suppression dans un `finally`) ; en secours, une fonction d'arrêt supprime ce qui reste et `dropLeftovers()` balaie les comptes
 * d'un run interrompu. Les droits portent sur UNE table que le test a créée (jamais sur le jeu de données partagé).
 *
 * Moteurs : MySQL / MariaDB / PostgreSQL. Les comptes sont créés avec le compte d'administration de la cible de test.
 */
final class TestAccounts
{
    private const PREFIX = 'tdb_t_';

    /** @var list<array{string, string}> nom, hôte (vide : PostgreSQL et rôles MariaDB) */
    private array $created = [];
    private string $role = '';
    private bool $shutdownRegistered = false;

    private function __construct(private readonly string $env)
    {
    }

    public static function forTarget(string $env): self
    {
        return new self($env);
    }

    /**
     * @template T
     *
     * @param callable(TestAccounts): T $then
     *
     * @return T
     */
    public static function using(string $env, callable $then): mixed
    {
        $accounts = new self($env);
        try {
            return $then($accounts);
        } finally {
            $accounts->dropAll();
        }
    }

    public static function isPostgres(string $env): bool
    {
        return Engine::PostgreSql === TargetDatabase::engine($env);
    }

    /** Nom du rôle accordé par `withInactiveRole()`. */
    public function roleName(): string
    {
        return '' !== $this->role ? $this->role : throw new \LogicException('No role was created.');
    }

    /** Membre en lecture seule : SELECT sur la table, rien d'autre. */
    public function readOnly(string $table, string $host = '%'): TestAccount
    {
        return $this->grantedUser($table, 'SELECT', $host);
    }

    /** Compte applicatif : SELECT, INSERT, UPDATE sur la table, ni DELETE ni DDL. */
    public function application(string $table, string $host = '%'): TestAccount
    {
        return $this->grantedUser($table, 'SELECT, INSERT, UPDATE', $host);
    }

    /** Compte dont le droit de lecture vient d'un rôle accordé mais NON ACTIF : il faut `SET ROLE` pour s'en servir. */
    public function withInactiveRole(string $table): TestAccount
    {
        $admin = $this->admin();
        $role = $this->newName().'_role';
        $account = $this->newAccount('%');
        $pg = self::isPostgres($this->env);
        $this->role = $role;
        if ($pg) {
            $this->remember($role, '');
            $admin->exec(\sprintf('CREATE ROLE %s NOLOGIN', $role));
            $admin->exec(\sprintf('GRANT SELECT ON TABLE %s TO %s', $this->ident($table), $role));
            $this->createUser($admin, $account);
            $admin->exec(\sprintf('GRANT %s TO %s WITH INHERIT FALSE', $role, $account->name));

            return $account;
        }
        $this->remember($role, Engine::MariaDb === TargetDatabase::engine($this->env) ? '' : '%');
        $admin->exec(\sprintf("CREATE ROLE '%s'", $role));
        $admin->exec(\sprintf("GRANT SELECT ON %s.%s TO '%s'", $this->schema(), $this->ident($table), $role));
        $this->createUser($admin, $account);
        $admin->exec(\sprintf("GRANT '%s' TO '%s'@'%%'", $role, $account->name));
        $admin->exec(Engine::MariaDb === TargetDatabase::engine($this->env)
            ? \sprintf("SET DEFAULT ROLE NONE FOR '%s'@'%%'", $account->name)
            : \sprintf("SET DEFAULT ROLE NONE TO '%s'@'%%'", $account->name));

        return $account;
    }

    /** Compte sans mot de passe (MySQL / MariaDB : connexion à mot de passe vide ; PostgreSQL : rôle sans mot de passe). */
    public function withoutPassword(string $host = '%'): TestAccount
    {
        $account = new TestAccount($this->env, $this->newName(), self::isPostgres($this->env) ? '' : $host, '');
        $this->createUser($this->admin(), $account);

        return $account;
    }

    /** Rôle PostgreSQL avec CREATEROLE. */
    public function withCreateRole(): TestAccount
    {
        if (!self::isPostgres($this->env)) {
            throw new \LogicException('CREATEROLE is a PostgreSQL attribute.');
        }
        $account = $this->newAccount('');
        $this->createUser($this->admin(), $account, 'CREATEROLE');

        return $account;
    }

    /** Compte à droits réduits : il se connecte, mais n'a aucun droit sur les tables, ni sur les schémas système. */
    public function limited(string $host = '%'): TestAccount
    {
        $account = $this->newAccount($host);
        $this->createUser($this->admin(), $account);

        return $account;
    }

    /** Supprime tout ce que cette instance a créé ; continue après une erreur et rend la première à la fin. */
    public function dropAll(): void
    {
        $first = null;
        foreach (array_reverse($this->created) as [$name, $host]) {
            try {
                self::drop($this->env, $name, $host);
            } catch (\Throwable $e) {
                $first ??= $e;
            }
        }
        $this->created = [];
        if (null !== $first) {
            throw $first;
        }
    }

    /**
     * Noms (avec l'hôte côté MySQL) des comptes et rôles de test présents sur la cible.
     *
     * @return list<string>
     */
    public static function remaining(string $env): array
    {
        return array_map(static fn (array $row): string => '' === $row[1] ? $row[0] : $row[0].'@'.$row[1], self::listed($env));
    }

    /** Balaie les comptes de test d'un run interrompu (retrouvés par leur préfixe). */
    public static function dropLeftovers(string $env): void
    {
        foreach (self::listed($env) as [$name, $host]) {
            self::drop($env, $name, $host);
        }
    }

    private function grantedUser(string $table, string $privileges, string $host): TestAccount
    {
        $admin = $this->admin();
        $account = $this->newAccount($host);
        $this->createUser($admin, $account);
        $target = self::isPostgres($this->env) ? $this->ident($table) : $this->schema().'.'.$this->ident($table);
        $admin->exec(self::isPostgres($this->env)
            ? \sprintf('GRANT %s ON TABLE %s TO %s', $privileges, $target, $account->name)
            : \sprintf("GRANT %s ON %s TO '%s'@'%s'", $privileges, $target, $account->name, $account->host));

        return $account;
    }

    private function newAccount(string $host): TestAccount
    {
        return new TestAccount($this->env, $this->newName(), self::isPostgres($this->env) ? '' : $host, bin2hex(random_bytes(8)));
    }

    private function createUser(\PDO $admin, TestAccount $account, string $options = ''): void
    {
        $this->remember($account->name, $account->host);
        if (self::isPostgres($this->env)) {
            $admin->exec(\sprintf(
                'CREATE ROLE %s LOGIN %s%s',
                $account->name,
                '' === $account->password ? '' : \sprintf("PASSWORD '%s' ", $account->password),
                $options,
            ));

            return;
        }
        $admin->exec(\sprintf(
            "CREATE USER '%s'@'%s'%s",
            $account->name,
            $account->host,
            '' === $account->password ? '' : \sprintf(" IDENTIFIED BY '%s'", $account->password),
        ));
    }

    /** Noté AVANT la création : un échec en cours de route est quand même nettoyé. */
    private function remember(string $name, string $host): void
    {
        $this->created[] = [$name, $host];
        if (!$this->shutdownRegistered) {
            $this->shutdownRegistered = true;
            register_shutdown_function(function (): void {
                try {
                    $this->dropAll();
                } catch (\Throwable) {
                }
            });
        }
    }

    private function newName(): string
    {
        return self::PREFIX.bin2hex(random_bytes(6));
    }

    private function admin(): \PDO
    {
        return LockPeer::connect($this->env);
    }

    private function schema(): string
    {
        return $this->ident(TargetDatabase::parameters($this->env)->database);
    }

    /** Les noms viennent des tests, jamais d'une saisie : on vérifie la forme plutôt que d'échapper. */
    private function ident(string $name): string
    {
        if (1 !== preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $name)) {
            throw new \InvalidArgumentException('Unexpected identifier.');
        }

        return self::isPostgres($this->env) ? $name : '`'.$name.'`';
    }

    private static function drop(string $env, string $name, string $host): void
    {
        $admin = LockPeer::connect($env);
        if (self::isPostgres($env)) {
            $admin->exec(\sprintf('DROP OWNED BY %s', $name));
            $admin->exec(\sprintf('DROP ROLE IF EXISTS %s', $name));

            return;
        }
        // MariaDB range un rôle sous l'hôte vide, que DROP USER ne retrouve pas.
        $admin->exec('' === $host ? \sprintf("DROP ROLE IF EXISTS '%s'", $name) : \sprintf("DROP USER IF EXISTS '%s'@'%s'", $name, $host));
    }

    /** @return list<array{string, string}> */
    private static function listed(string $env): array
    {
        $admin = LockPeer::connect($env);
        $pg = self::isPostgres($env);
        $statement = $admin->query($pg
            ? "SELECT rolname, '' FROM pg_roles WHERE rolname LIKE 'tdb\\_t\\_%' ORDER BY 1"
            : "SELECT user, host FROM mysql.user WHERE user LIKE 'tdb\\\\_t\\\\_%' ORDER BY 1, 2");
        $rows = [];
        foreach (false === $statement ? [] : $statement->fetchAll(\PDO::FETCH_NUM) as $row) {
            if (\is_array($row) && \is_string($row[0] ?? null) && \is_string($row[1] ?? null)) {
                $rows[] = [$row[0], $row[1]];
            }
        }

        return $rows;
    }
}
