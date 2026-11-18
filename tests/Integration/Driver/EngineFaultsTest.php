<?php

declare(strict_types=1);

namespace App\Tests\Integration\Driver;

use App\Core\Connection\ConnectionParameters;
use App\Core\Connection\Engine;
use App\Core\Data\DataChangeService;
use App\Core\Data\DataDraft;
use App\Core\Data\Exception\DataChangeFailedException;
use App\Core\Data\RowInsert;
use App\Core\Driver\EngineFault;
use App\Core\Driver\Exception\EngineFaultException;
use App\Core\Driver\Exception\TargetConnectionFailedException;
use App\Core\Driver\TargetConnection;
use App\Core\Guard\StatementKind;
use App\Core\Query\QueryMode;
use App\Core\Query\QueryOptions;
use App\Core\Schema\Change\DdlStatement;
use App\Core\Schema\Change\Exception\SchemaChangeFailedException;
use App\Driver\DbalConnector;
use App\Driver\DbalDdlExecutor;
use App\Driver\MySql\MySqlDriver;
use App\Driver\PostgreSql\PostgreSqlDriver;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Les erreurs courantes du moteur, provoquées pour de vrai sur une table jetable et un compte jetable (créés puis supprimés) :
 * doublon, droit refusé, identifiants refusés, limite de connexions du compte. Le code du moteur est classé, jamais son texte.
 */
#[Group('integration')]
final class EngineFaultsTest extends TestCase
{
    private Engine $engine;
    private string $env = '';
    private string $table = '';
    private string $user = '';
    private string $password = '';
    private ?\PDO $admin = null;
    /** @var list<TargetConnection> */
    private array $targets = [];
    /** @var list<\Doctrine\DBAL\Connection> */
    private array $connections = [];

    /**
     * @return iterable<string, array{string}>
     */
    public static function engines(): iterable
    {
        yield from TargetDatabase::relational();
    }

    protected function tearDown(): void
    {
        foreach ($this->targets as $target) {
            $target->close();
        }
        foreach ($this->connections as $connection) {
            $connection->close();
        }
        if (null !== $this->admin) {
            $this->admin->exec(\sprintf('DROP TABLE IF EXISTS %s', $this->q()));
            if ('' !== $this->user) {
                $this->admin->exec(Engine::PostgreSql === $this->engine ? \sprintf('DROP ROLE IF EXISTS %s', $this->user) : \sprintf("DROP USER IF EXISTS '%s'@'%%'", $this->user));
            }
        }
    }

    private function open(string $env): void
    {
        $this->env = $env;
        $this->engine = TargetDatabase::engine($env);
        try {
            $this->admin = LockPeer::connect($env);
        } catch (\Throwable) {
            self::markTestSkipped('The target is unreachable (docker compose --profile targets up -d --wait).');
        }
        $this->table = 'tdb_fault_'.bin2hex(random_bytes(4));
        $this->admin->exec(\sprintf('CREATE TABLE %s (id int NOT NULL PRIMARY KEY, email varchar(40) NOT NULL UNIQUE)', $this->q()));
        $this->admin->exec(\sprintf("INSERT INTO %s (id, email) VALUES (1, 'taken@example.org')", $this->q()));
    }

    private function q(): string
    {
        return Engine::PostgreSql === $this->engine ? '"'.$this->table.'"' : '`'.$this->table.'`';
    }

    private function connect(ConnectionParameters $parameters): TargetConnection
    {
        $target = Engine::PostgreSql === $this->engine
            ? (new PostgreSqlDriver(new DbalConnector()))->connect($this->engine, $parameters)
            : (new MySqlDriver(new DbalConnector()))->connect($this->engine, $parameters);

        return $this->targets[] = $target;
    }

    /** Un compte jetable, avec le droit de lire la table jetable seulement. */
    private function limitedAccount(string $extra = ''): ConnectionParameters
    {
        $this->user = 'tdb_fault_'.bin2hex(random_bytes(4));
        $this->password = bin2hex(random_bytes(12));
        $admin = $this->admin ?? throw new \LogicException('Not opened.');
        $real = TargetDatabase::parameters($this->env);
        if (Engine::PostgreSql === $this->engine) {
            $admin->exec(\sprintf("CREATE ROLE %s LOGIN PASSWORD '%s' %s", $this->user, $this->password, $extra));
            $admin->exec(\sprintf('GRANT SELECT ON %s TO %s', $this->q(), $this->user));
        } else {
            $admin->exec(\sprintf("CREATE USER '%s'@'%%' IDENTIFIED BY '%s' %s", $this->user, $this->password, $extra));
            $admin->exec(\sprintf("GRANT SELECT ON `%s`.%s TO '%s'@'%%'", $real->database, $this->q(), $this->user));
        }

        return new ConnectionParameters($real->host, $real->port, $real->database, $this->user, $this->password);
    }

    #[DataProvider('engines')]
    public function testADuplicateKeyThroughTheRunnerIsClassifiedWithoutTheValue(string $env): void
    {
        $this->open($env);
        $target = $this->connect(TargetDatabase::parameters($env));

        try {
            $target->runner()->run(\sprintf("INSERT INTO %s (id, email) VALUES (2, 'taken@example.org')", $this->q()), StatementKind::Write, new QueryOptions(mode: QueryMode::ReadWrite));
            self::fail('Expected a duplicate key.');
        } catch (EngineFaultException $e) {
            self::assertSame(EngineFault::DuplicateKey, $e->fault);
            self::assertStringNotContainsString('taken@example.org', $e->getMessage());
        }
        // La connexion reste utilisable et rien n'a été écrit.
        self::assertSame([[1]], $target->runner()->run(\sprintf('SELECT count(*) FROM %s', $this->q()), StatementKind::Read, new QueryOptions())->rows);
    }

    #[DataProvider('engines')]
    public function testADuplicateKeyOnADataChangeIsClassified(string $env): void
    {
        $this->open($env);
        $target = $this->connect(TargetDatabase::parameters($env));

        try {
            (new DataChangeService())->apply($target, $this->table, new DataDraft([], [new RowInsert(['id' => 2, 'email' => 'taken@example.org'])], []), false);
            self::fail('Expected a duplicate key.');
        } catch (DataChangeFailedException $e) {
            self::assertSame(EngineFault::DuplicateKey, $e->fault);
            self::assertSame(0, $e->applied);
        }
    }

    #[DataProvider('engines')]
    public function testAWriteWithoutTheRightIsClassifiedAsTheEnginesRefusal(string $env): void
    {
        $this->open($env);
        $target = $this->connect($this->limitedAccount());

        try {
            $target->runner()->run(\sprintf("INSERT INTO %s (id, email) VALUES (2, 'x@example.org')", $this->q()), StatementKind::Write, new QueryOptions(mode: QueryMode::ReadWrite));
            self::fail('Expected a refusal of the engine.');
        } catch (EngineFaultException $e) {
            self::assertSame(EngineFault::PermissionDenied, $e->fault);
        }
        try {
            (new DataChangeService())->apply($target, $this->table, new DataDraft([], [new RowInsert(['id' => 2, 'email' => 'x@example.org'])], []), false);
            self::fail('Expected a refusal of the engine.');
        } catch (DataChangeFailedException $e) {
            self::assertSame(EngineFault::PermissionDenied, $e->fault);
        }
    }

    #[DataProvider('engines')]
    public function testAStructureChangeWithoutTheRightIsClassified(string $env): void
    {
        $this->open($env);
        $parameters = $this->limitedAccount();
        $connection = (new DbalConnector())->connect($this->engine, $parameters);
        $this->connections[] = $connection;

        try {
            (new DbalDdlExecutor($connection, $this->engine))->execute([new DdlStatement(\sprintf('ALTER TABLE %s ADD COLUMN extra int NULL', $this->q()), 'add_column', $this->table, false)], Engine::PostgreSql === $this->engine);
            self::fail('Expected a refusal of the engine.');
        } catch (SchemaChangeFailedException $e) {
            self::assertSame(EngineFault::PermissionDenied, $e->fault);
            self::assertSame([], $e->applied);
        }
    }

    #[DataProvider('engines')]
    public function testAWrongPasswordIsClassifiedAtConnection(string $env): void
    {
        $this->open($env);

        try {
            (new DbalConnector())->connect($this->engine, TargetDatabase::parameters($env)->withPassword('wrong-secret-value'));
            self::fail('Expected a refusal.');
        } catch (TargetConnectionFailedException $e) {
            self::assertSame(EngineFault::AuthenticationFailed, $e->fault);
            self::assertStringNotContainsString('wrong-secret-value', $e->getMessage());
        }
    }

    #[DataProvider('engines')]
    public function testAnUnknownDatabaseOrAClosedPortStayGeneric(string $env): void
    {
        $this->open($env);
        $real = TargetDatabase::parameters($env);

        foreach ([new ConnectionParameters($real->host, $real->port, 'no_such_database', $real->username, $real->password()), new ConnectionParameters($real->host, 1, $real->database, $real->username, $real->password())] as $parameters) {
            try {
                (new DbalConnector())->connect($this->engine, $parameters);
                self::fail('Expected a refusal.');
            } catch (TargetConnectionFailedException $e) {
                self::assertNull($e->fault);
            }
        }
    }

    #[DataProvider('engines')]
    public function testTheAccountsConnectionLimitIsClassifiedAsTooManyConnections(string $env): void
    {
        $this->open($env);
        $parameters = $this->limitedAccount(Engine::PostgreSql === $this->engine ? 'CONNECTION LIMIT 1' : 'WITH MAX_USER_CONNECTIONS 1');
        $first = (new DbalConnector())->connect($this->engine, $parameters);
        $this->connections[] = $first;

        try {
            (new DbalConnector())->connect($this->engine, $parameters);
            self::fail('The second connection of the account should be refused.');
        } catch (TargetConnectionFailedException $e) {
            self::assertSame(EngineFault::TooManyConnections, $e->fault);
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function mysqlFamily(): iterable
    {
        yield 'MySQL' => ['TARGET_MYSQL_URL'];
        yield 'MySQL 26' => ['TARGET_MYSQL26_URL'];
        yield 'MariaDB' => ['TARGET_MARIADB_URL'];
        yield 'MariaDB 13' => ['TARGET_MARIADB13_URL'];
    }

    #[DataProvider('mysqlFamily')]
    public function testAnAccountWithoutAccessToTheDatabaseIsClassifiedAtConnection(string $env): void
    {
        $this->open($env);
        $parameters = $this->limitedAccount();
        $real = TargetDatabase::parameters($env);
        $this->admin?->exec(\sprintf("REVOKE SELECT ON `%s`.%s FROM '%s'@'%%'", $real->database, $this->q(), $this->user));

        try {
            (new DbalConnector())->connect($this->engine, $parameters);
            self::fail('Expected a refusal.');
        } catch (TargetConnectionFailedException $e) {
            self::assertSame(EngineFault::PermissionDenied, $e->fault);
        }
    }
}
