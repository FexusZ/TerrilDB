<?php

declare(strict_types=1);

namespace App\Tests\Integration\Driver;

use App\Core\Connection\Engine;
use App\Core\Data\DataChangeService;
use App\Core\Data\DataDraft;
use App\Core\Data\Exception\DataChangeFailedException;
use App\Core\Data\RowUpdate;
use App\Core\Driver\Exception\LockConflictException;
use App\Core\Driver\LockConflict;
use App\Core\Driver\TargetConnection;
use App\Core\Guard\StatementKind;
use App\Core\Query\QueryMode;
use App\Core\Query\QueryOptions;
use App\Core\Schema\Change\DdlStatement;
use App\Core\Schema\Change\Exception\SchemaChangeFailedException;
use App\Driver\DbalConnector;
use App\Driver\DbalDdlExecutor;
use App\Driver\EngineLockConflict;
use App\Driver\MySql\MySqlDriver;
use App\Driver\PostgreSql\PostgreSqlDriver;
use App\Http\Json\ErrorMapper;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Un vrai interblocage et un vrai délai de verrou, sur des tables jetables : le code du moteur est classé, la transaction est
 * annulée entièrement.
 */
#[Group('integration')]
final class LockConflictTest extends TestCase
{
    private ?TargetConnection $target = null;
    private ?\PDO $blocker = null;
    private Engine $engine;
    private string $env = '';
    private string $table = '';

    /**
     * @return iterable<string, array{string}>
     */
    public static function engines(): iterable
    {
        yield from TargetDatabase::relational();
    }

    protected function tearDown(): void
    {
        $this->blocker?->exec('ROLLBACK');
        $this->blocker = null;
        $this->target?->close();
        if ('' !== $this->table) {
            LockPeer::connect($this->env)->exec(\sprintf('DROP TABLE IF EXISTS %s', $this->q()));
            LockPeer::connect($this->env)->exec(\sprintf('DROP TABLE IF EXISTS %s', $this->q('_side')));
        }
    }

    private function open(string $env): void
    {
        $this->env = $env;
        $this->engine = TargetDatabase::engine($env);
        try {
            $this->target = $this->driverConnect();
        } catch (\Throwable) {
            self::markTestSkipped('The target is unreachable (docker compose --profile targets up -d --wait).');
        }
        $this->table = 'tdb_lock_'.bin2hex(random_bytes(4));
        $pdo = LockPeer::connect($env);
        $pdo->exec(\sprintf('CREATE TABLE %s (id int NOT NULL PRIMARY KEY, v int NOT NULL)', $this->q()));
        $pdo->exec(\sprintf('INSERT INTO %s (id, v) VALUES (1, 0), (2, 0), (3, 0), (4, 0)', $this->q()));
    }

    private function driverConnect(): TargetConnection
    {
        $parameters = TargetDatabase::parameters($this->env);

        return Engine::PostgreSql === $this->engine
            ? (new PostgreSqlDriver(new DbalConnector()))->connect($this->engine, $parameters)
            : (new MySqlDriver(new DbalConnector()))->connect($this->engine, $parameters);
    }

    private function target(): TargetConnection
    {
        return $this->target ?? throw new \LogicException('Not opened.');
    }

    private function q(string $suffix = ''): string
    {
        return Engine::PostgreSql === $this->engine ? '"'.$this->table.$suffix.'"' : '`'.$this->table.$suffix.'`';
    }

    /** Une transaction ouverte qui tient la ligne 1. */
    private function holdRowOne(): void
    {
        $this->blocker = LockPeer::connect($this->env);
        $this->blocker->exec('BEGIN');
        $this->blocker->exec(\sprintf('UPDATE %s SET v = 99 WHERE id = 1', $this->q()));
    }

    /** Délai de verrou court sur la session testée : le test ne dure pas 50 secondes. */
    private function shortenLockWait(): void
    {
        $sql = Engine::PostgreSql === $this->engine ? "SET lock_timeout = '500ms'" : 'SET SESSION innodb_lock_wait_timeout = 1';
        $this->target()->runner()->run($sql, StatementKind::Write, new QueryOptions(mode: QueryMode::ReadWrite));
    }

    /**
     * @return list<int>
     */
    private function values(): array
    {
        $statement = LockPeer::connect($this->env)->query(\sprintf('SELECT v FROM %s ORDER BY id', $this->q()));
        self::assertInstanceOf(\PDOStatement::class, $statement);

        return array_values(array_map(static fn (mixed $v): int => is_numeric($v) ? (int) $v : 0, $statement->fetchAll(\PDO::FETCH_COLUMN)));
    }

    private function draft(): DataDraft
    {
        return new DataDraft([new RowUpdate(['id' => 1, 'v' => 0], ['v' => 10]), new RowUpdate(['id' => 2, 'v' => 0], ['v' => 20])], [], []);
    }

    #[DataProvider('engines')]
    public function testALockWaitTimeoutOnDataIsClassifiedAndRolledBackWhole(string $env): void
    {
        $this->open($env);
        $this->shortenLockWait();
        $this->holdRowOne();

        // La ligne 2 est libre et passe en premier : elle doit être annulée avec le reste.
        $draft = new DataDraft([new RowUpdate(['id' => 2, 'v' => 0], ['v' => 20]), new RowUpdate(['id' => 1, 'v' => 0], ['v' => 10])], [], []);
        try {
            (new DataChangeService())->apply($this->target(), $this->table, $draft, false);
            self::fail('The blocked row should have timed out.');
        } catch (DataChangeFailedException $e) {
            self::assertSame(LockConflict::LockTimeout, $e->lock);
            self::assertSame(0, $e->applied);
        }
        self::assertSame([0, 0, 0, 0], $this->values());
    }

    #[DataProvider('engines')]
    public function testADeadlockOnDataIsClassifiedAsDeadlockNotAsTimeout(string $env): void
    {
        $this->open($env);

        $script = \dirname(__DIR__, 3).'/tests/Integration/Driver/lock_peer.php';
        $process = proc_open([\PHP_BINARY, $script, $env, $this->q()], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        self::assertIsResource($process);
        try {
            self::assertSame("ready\n", fgets($pipes[1]));
            try {
                (new DataChangeService())->apply($this->target(), $this->table, $this->draft(), false);
                self::fail('The session should have been chosen as the deadlock victim.');
            } catch (DataChangeFailedException $e) {
                self::assertSame(LockConflict::Deadlock, $e->lock);
                self::assertSame(0, $e->applied);
            }
            self::assertSame("done\n", fgets($pipes[1]));
        } finally {
            proc_close($process);
        }
        // Seule la pair a validé : rien de la session victime n'a survécu.
        self::assertSame([1, 1, 1, 1], $this->values());
    }

    public function testASerializationFailureOnPostgreSqlIsClassifiedUnderItsOwnCode(): void
    {
        $this->open('TARGET_POSTGRES_URL');

        // Deux transactions en lecture répétable qui modifient la même ligne : la seconde reçoit 40001, pas un interblocage.
        $first = LockPeer::connect($this->env);
        $first->exec('BEGIN ISOLATION LEVEL REPEATABLE READ');
        $read = $first->query(\sprintf('SELECT v FROM %s WHERE id = 1', $this->q()));
        self::assertInstanceOf(\PDOStatement::class, $read);
        $read->fetchAll();
        LockPeer::connect($this->env)->exec(\sprintf('UPDATE %s SET v = 5 WHERE id = 1', $this->q()));
        try {
            $first->exec(\sprintf('UPDATE %s SET v = 6 WHERE id = 1', $this->q()));
            self::fail('PostgreSQL should refuse the update with a serialization failure.');
        } catch (\PDOException $e) {
            self::assertSame(LockConflict::SerializationFailure, EngineLockConflict::of(Engine::PostgreSql, $e));
        } finally {
            $first->exec('ROLLBACK');
        }
    }

    #[DataProvider('engines')]
    public function testALockWaitTimeoutOnAStatementThroughTheRunnerIsClassified(string $env): void
    {
        $this->open($env);
        $this->shortenLockWait();
        $this->holdRowOne();

        try {
            $this->target()->runner()->run(\sprintf('UPDATE %s SET v = 5 WHERE id = 1', $this->q()), StatementKind::Write, new QueryOptions(mode: QueryMode::ReadWrite));
            self::fail('The blocked statement should have timed out.');
        } catch (LockConflictException $e) {
            self::assertSame(LockConflict::LockTimeout, $e->conflict);
        }
        self::assertSame([0, 0, 0, 0], $this->values());
    }

    #[DataProvider('engines')]
    public function testALockWaitTimeoutOnStructureIsClassified(string $env): void
    {
        $this->open($env);
        $this->holdRowOne();

        $connection = (new DbalConnector())->connect($this->engine, TargetDatabase::parameters($env));
        try {
            (new DbalDdlExecutor($connection, $this->engine, 1))->execute([new DdlStatement(\sprintf('ALTER TABLE %s ADD COLUMN extra int NULL', $this->q()), 'add_column', $this->table, false)], Engine::PostgreSql === $this->engine);
            self::fail('The table is in use: the change should have timed out.');
        } catch (SchemaChangeFailedException $e) {
            self::assertSame(LockConflict::LockTimeout, $e->lock);
            self::assertSame([], $e->applied);
        } finally {
            $connection->close();
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function stepByStepEngines(): iterable
    {
        yield 'MySQL' => ['TARGET_MYSQL_URL'];
        yield 'MySQL 26' => ['TARGET_MYSQL26_URL'];
        yield 'MariaDB' => ['TARGET_MARIADB_URL'];
        yield 'MariaDB 13' => ['TARGET_MARIADB13_URL'];
    }

    /** Un DDL joué instruction par instruction : la première reste en place, la réponse ne dit donc pas de rejouer. */
    #[DataProvider('stepByStepEngines')]
    public function testALockWaitAfterAnAppliedStatementIsNotRetryable(string $env): void
    {
        $this->open($env);
        LockPeer::connect($env)->exec(\sprintf('CREATE TABLE %s (id int NOT NULL PRIMARY KEY)', $this->q('_side')));
        $this->holdRowOne();

        $connection = (new DbalConnector())->connect($this->engine, TargetDatabase::parameters($env));
        try {
            (new DbalDdlExecutor($connection, $this->engine, 1))->execute([
                new DdlStatement(\sprintf('ALTER TABLE %s ADD COLUMN extra int NULL', $this->q('_side')), 'add_column', $this->table.'_side', false),
                new DdlStatement(\sprintf('ALTER TABLE %s ADD COLUMN extra int NULL', $this->q()), 'add_column', $this->table, false),
            ], false);
            self::fail('The second statement should have timed out.');
        } catch (SchemaChangeFailedException $e) {
            self::assertSame(LockConflict::LockTimeout, $e->lock);
            self::assertCount(1, $e->applied);
            $response = (new ErrorMapper())->map($e);
            self::assertSame('lock_timeout', $response?->code);
            self::assertFalse($response->extra['retryable']);
            self::assertIsArray($response->extra['applied']);
            self::assertCount(1, $response->extra['applied']);
        } finally {
            $connection->close();
        }
    }
}
