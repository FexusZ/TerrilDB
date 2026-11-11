<?php

declare(strict_types=1);

namespace App\Tests\Integration\Driver;

use App\Core\Connection\Engine;
use App\Core\Data\DataChangeService;
use App\Core\Data\DataDraft;
use App\Core\Data\Exception\DataChangeConflictException;
use App\Core\Data\Exception\DataChangeNeedsConfirmationException;
use App\Core\Data\RowDelete;
use App\Core\Data\RowInsert;
use App\Core\Data\RowUpdate;
use App\Core\Driver\Exception\TargetConnectionFailedException;
use App\Core\Driver\TargetConnection;
use App\Core\Guard\StatementKind;
use App\Core\Query\QueryOptions;
use App\Core\Schema\Change\DdlStatement;
use App\Driver\ClickHouse\ClickHouseDriver;
use App\Driver\DbalConnector;
use App\Driver\MySql\MySqlDriver;
use App\Driver\PostgreSql\PostgreSqlDriver;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\HttpClient;

/**
 * L'édition de données rejouée contre les vrais moteurs, sur des tables jetables.
 */
#[Group('integration')]
final class DataChangeTest extends TestCase
{
    private ?TargetConnection $target = null;
    private Engine $engine;
    private string $env = '';
    private string $table;

    protected function tearDown(): void
    {
        if (null === $this->target) {
            return;
        }
        // Une lecture non tamponnée (MySQL) peut rester ouverte sur la connexion du test : le nettoyage passe par une neuve.
        $this->target->close();
        $this->target = $this->connect();
        try {
            $this->raw(\sprintf('DROP TABLE IF EXISTS %s', $this->q($this->table)));
        } catch (\Throwable) {
        }
        $this->target?->close();
    }

    private function connect(): TargetConnection
    {
        $parameters = TargetDatabase::parameters($this->env);

        return match ($this->engine) {
            Engine::PostgreSql => (new PostgreSqlDriver(new DbalConnector()))->connect($this->engine, $parameters),
            Engine::ClickHouse => (new ClickHouseDriver(HttpClient::create()))->connect($this->engine, $parameters),
            default => (new MySqlDriver(new DbalConnector()))->connect($this->engine, $parameters),
        };
    }

    private function open(string $env): void
    {
        $this->env = $env;
        $this->engine = TargetDatabase::engine($env);
        try {
            $this->target = $this->connect();
        } catch (TargetConnectionFailedException) {
            self::markTestSkipped('The target is unreachable (docker compose --profile targets up -d --wait).');
        }
        $this->table = 'tdb_data_'.bin2hex(random_bytes(4));
        $t = $this->q($this->table);
        $this->raw(match ($this->engine) {
            Engine::PostgreSql => \sprintf('CREATE TABLE %s (id integer NOT NULL PRIMARY KEY, name character varying(40) NOT NULL, note text)', $t),
            Engine::ClickHouse => \sprintf('CREATE TABLE %s (id UInt32, name String, note Nullable(String)) ENGINE = MergeTree ORDER BY id', $t),
            default => \sprintf('CREATE TABLE %s (id int NOT NULL PRIMARY KEY, name varchar(40) NOT NULL, note text NULL) ENGINE=InnoDB', $t),
        });
        $this->raw(\sprintf("INSERT INTO %s (id, name, note) VALUES (1, 'alpha', NULL), (2, 'beta', 'b'), (3, 'gamma', 'g')", $t));
    }

    private function q(string $name): string
    {
        return Engine::PostgreSql === $this->engine ? '"'.$name.'"' : '`'.$name.'`';
    }

    private function raw(string $sql): void
    {
        \assert(null !== $this->target);
        $this->target->ddl()->execute([new DdlStatement($sql, 'raw', 'raw', false)], false);
    }

    /**
     * @return list<list<mixed>>
     */
    private function rows(): array
    {
        \assert(null !== $this->target);
        $sql = \sprintf('SELECT id, name, note FROM %s ORDER BY id', $this->q($this->table));

        return array_map(static fn (array $row): array => [(int) $row[0], $row[1], $row[2]], $this->target->runner()->run($sql, StatementKind::Read, new QueryOptions())->rows);
    }

    private function service(): DataChangeService
    {
        return new DataChangeService();
    }

    /** ClickHouse applique modifications et suppressions en arrière-plan : on attend la fin des mutations avant de relire. */
    private function settle(): void
    {
        if (Engine::ClickHouse !== $this->engine) {
            return;
        }
        for ($i = 0; $i < 50; ++$i) {
            $pending = $this->target?->runner()->run(\sprintf("SELECT count() FROM system.mutations WHERE table = '%s' AND NOT is_done", $this->table), StatementKind::Read, new QueryOptions())->rows[0][0] ?? 0;
            if (0 === (int) $pending) {
                return;
            }
            usleep(100_000);
        }
    }

    #[DataProvider('engines')]
    public function testUpdateInsertAndDeleteAreApplied(string $env): void
    {
        $this->open($env);
        \assert(null !== $this->target);

        $draft = new DataDraft(
            [new RowUpdate(['id' => 2, 'name' => 'beta', 'note' => 'b'], ['name' => "be'ta", 'note' => null])],
            [new RowInsert(['id' => 4, 'name' => 'delta', 'note' => 'd'])],
            [new RowDelete(['id' => 3, 'name' => 'gamma', 'note' => 'g'])],
        );

        $preview = $this->service()->preview($this->target, $this->table, $draft);
        self::assertTrue($preview->ok());
        self::assertSame([1, 1, null], $preview->matches);

        $plan = $this->service()->apply($this->target, $this->table, $draft, true);
        $this->settle();

        self::assertSame(Engine::ClickHouse === $this->engine, $plan->asynchronous);
        self::assertSame([[1, 'alpha', null], [2, "be'ta", null], [4, 'delta', 'd']], $this->rows());
    }

    #[DataProvider('engines')]
    public function testARowChangedMeanwhileIsRefusedAndNothingIsWritten(string $env): void
    {
        $this->open($env);
        \assert(null !== $this->target);

        $draft = new DataDraft(
            [new RowUpdate(['id' => 1, 'name' => 'alpha', 'note' => null], ['name' => 'one']), new RowUpdate(['id' => 2, 'name' => 'STALE', 'note' => 'b'], ['name' => 'two'])],
            [],
            [],
        );

        try {
            $this->service()->apply($this->target, $this->table, $draft, false);
            self::fail('A stale row should be refused.');
        } catch (DataChangeConflictException $e) {
            self::assertSame(1, $e->statementIndex);
            self::assertSame(0, $e->matched);
        }
        self::assertSame([[1, 'alpha', null], [2, 'beta', 'b'], [3, 'gamma', 'g']], $this->rows());
    }

    #[DataProvider('engines')]
    public function testDeletingNeedsConfirmation(string $env): void
    {
        $this->open($env);
        \assert(null !== $this->target);

        $this->expectException(DataChangeNeedsConfirmationException::class);

        $this->service()->apply($this->target, $this->table, new DataDraft([], [], [new RowDelete(['id' => 1, 'name' => 'alpha', 'note' => null])]), false);
    }

    #[DataProvider('engines')]
    public function testARowThatTheEngineRefusesRollsEverythingBack(string $env): void
    {
        if ('TARGET_CLICKHOUSE_URL' === $env) {
            self::markTestSkipped('ClickHouse has no transaction.');
        }
        $this->open($env);
        \assert(null !== $this->target);

        try {
            $this->service()->apply($this->target, $this->table, new DataDraft([], [new RowInsert(['id' => 9, 'name' => 'ok']), new RowInsert(['id' => 1, 'name' => 'duplicate'])], []), false);
            self::fail('A duplicate key should be refused.');
        } catch (\App\Core\Data\Exception\DataChangeFailedException $e) {
            self::assertSame(0, $e->applied);
            self::assertStringContainsString('duplicate', $e->failed->display);
        }
        self::assertSame([[1, 'alpha', null], [2, 'beta', 'b'], [3, 'gamma', 'g']], $this->rows());
    }

    #[DataProvider('relationalEngines')]
    public function testAValueTheEngineStoresUnchangedIsNotMistakenForAConflict(string $env): void
    {
        $this->open($env);
        \assert(null !== $this->target);
        $t = $this->q($this->table);
        $this->raw(\sprintf('ALTER TABLE %s ADD COLUMN stamp %s', $t, Engine::PostgreSql === $this->engine ? 'timestamp(0)' : 'datetime'));
        $this->raw(\sprintf("UPDATE %s SET stamp = '2024-01-01 07:00:00' WHERE id = 1", $t));

        // « .0 » : le moteur range la même valeur (MySQL annonce alors 0 ligne touchée).
        $draft = new DataDraft([new RowUpdate(['id' => 1, 'stamp' => '2024-01-01 07:00:00'], ['stamp' => '2024-01-01 07:00:00.0'])], [], []);

        \assert(null !== $this->target);
        $this->service()->apply($this->target, $this->table, $draft, false);

        self::assertSame([[1, 'alpha', null], [2, 'beta', 'b'], [3, 'gamma', 'g']], $this->rows());
    }

    /** Une connexion neuve par lecture : la session de lecture de MySQL reste en lecture seule. */
    private function readDoc(string $quotedTable): string
    {
        $reader = $this->connect();
        try {
            $value = $reader->runner()->run(\sprintf('SELECT doc FROM %s WHERE id = 1', $quotedTable), StatementKind::Read, new QueryOptions())->rows[0][0];
        } finally {
            $reader->close();
        }
        self::assertIsString($value);

        return $value;
    }

    #[DataProvider('relationalEngines')]
    public function testAJsonColumnIsEditedWhateverTheEngineCallsIt(string $env): void
    {
        $this->open($env);
        \assert(null !== $this->target);
        $t = $this->q($this->table);
        // MariaDB : « json » est un alias de longtext (le type relu s'appelle longtext) ; MySQL et PostgreSQL gardent json.
        $this->raw(\sprintf('ALTER TABLE %s ADD COLUMN doc json', $t));
        $this->raw(\sprintf('UPDATE %s SET doc = \'{"a": 1, "b": [1, 2]}\' WHERE id = 1', $t));
        $original = $this->readDoc($t);

        \assert(null !== $this->target);
        $draft = new DataDraft([new RowUpdate(['id' => 1, 'doc' => $original], ['doc' => '{"a": 2}'])], [], []);
        $this->service()->apply($this->target, $this->table, $draft, false);

        $now = $this->readDoc($t);
        self::assertSame(['a' => 2], json_decode($now, true));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function relationalEngines(): iterable
    {
        yield from TargetDatabase::relational();
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function engines(): iterable
    {
        yield from TargetDatabase::all();
    }
}
