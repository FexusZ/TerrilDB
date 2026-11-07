<?php

declare(strict_types=1);

namespace App\Tests\Integration\Driver;

use App\Core\Connection\Engine;
use App\Core\Driver\Exception\TargetConnectionFailedException;
use App\Core\Driver\TargetConnection;
use App\Core\Schema\Change\ColumnDraft;
use App\Core\Schema\Change\DdlStatement;
use App\Core\Schema\Change\Exception\SchemaChangeFailedException;
use App\Core\Schema\Change\ForeignKeyDraft;
use App\Core\Schema\Change\IndexDraft;
use App\Core\Schema\Change\SchemaChangePlanner;
use App\Core\Schema\Change\TableDraft;
use App\Core\Schema\ReferentialAction;
use App\Core\Schema\TableDefinition;
use App\Driver\ClickHouse\ClickHouseDriver;
use App\Driver\DbalConnector;
use App\Driver\MySql\MySqlDriver;
use App\Driver\PostgreSql\PostgreSqlDriver;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\HttpClient;

/**
 * Les générateurs de structure rejoués contre les vrais moteurs, sur des tables jetables : ce qu'on montre à l'utilisateur
 * est bien ce que le moteur accepte, et le résultat se relit tel qu'attendu.
 */
#[Group('integration')]
final class SchemaChangeTest extends TestCase
{
    private ?TargetConnection $target = null;
    private Engine $engine;
    private SchemaChangePlanner $planner;

    /** @var list<string> */
    private array $tables = [];

    protected function setUp(): void
    {
        $this->planner = new SchemaChangePlanner();
    }

    protected function tearDown(): void
    {
        foreach (array_reverse($this->tables) as $table) {
            try {
                $this->raw(\sprintf('DROP TABLE IF EXISTS %s', $this->q($table)));
            } catch (\Throwable) {
            }
        }
        $this->target?->close();
    }

    private function open(string $env): void
    {
        $this->engine = TargetDatabase::engine($env);
        $parameters = TargetDatabase::parameters($env);
        try {
            $this->target = match ($this->engine) {
                Engine::PostgreSql => (new PostgreSqlDriver(new DbalConnector()))->connect($this->engine, $parameters),
                Engine::ClickHouse => (new ClickHouseDriver(HttpClient::create()))->connect($this->engine, $parameters),
                default => (new MySqlDriver(new DbalConnector()))->connect($this->engine, $parameters),
            };
        } catch (TargetConnectionFailedException) {
            self::markTestSkipped('The target is unreachable (docker compose --profile targets up -d --wait).');
        }
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

    private function describe(string $name): TableDefinition
    {
        \assert(null !== $this->target);

        return $this->target->introspector()->describeTable($name);
    }

    private function scratch(string $prefix = 'tdb_edit'): string
    {
        return $this->tables[] = $prefix.'_'.bin2hex(random_bytes(4));
    }

    private function int(): string
    {
        return Engine::PostgreSql === $this->engine ? 'integer' : 'int';
    }

    private function varchar(int $length): string
    {
        return (Engine::PostgreSql === $this->engine ? 'character varying' : 'varchar').'('.$length.')';
    }

    private function createBase(string $table): void
    {
        $t = $this->q($table);
        if (Engine::PostgreSql === $this->engine) {
            $this->raw(\sprintf('CREATE TABLE %s (id integer NOT NULL PRIMARY KEY, name character varying(40) NOT NULL, note text)', $t));
            $this->raw(\sprintf('CREATE INDEX %s ON %s (name)', $this->q('idx_name'.substr($table, -8)), $t));

            return;
        }
        $this->raw(\sprintf('CREATE TABLE %s (id int NOT NULL PRIMARY KEY, name varchar(40) NOT NULL, note text NULL, INDEX %s (name)) ENGINE=InnoDB', $t, $this->q('idx_name'.substr($table, -8))));
    }

    private static function col(TableDefinition $table, string $name): \App\Core\Schema\ColumnDefinition
    {
        return $table->column($name) ?? throw new \LogicException('Missing column '.$name);
    }

    private static function draftOf(TableDefinition $table): TableDraft
    {
        return new TableDraft(
            $table->name,
            array_map(static fn ($c): ColumnDraft => new ColumnDraft($c->name, $c->name, $c->type, $c->nullable, $c->default, $c->autoIncrement), $table->columns),
            array_map(static fn ($i): IndexDraft => new IndexDraft($i->name, $i->name, $i->columns, $i->unique, $i->primary, null, null), $table->indexes),
            array_map(static fn ($f): ForeignKeyDraft => new ForeignKeyDraft($f->name, $f->name, $f->columns, $f->referencedTable, $f->referencedColumns, $f->onUpdate, $f->onDelete), $table->foreignKeys),
        );
    }

    private function apply(TableDefinition $before, TableDraft $draft): TableDefinition
    {
        \assert(null !== $this->target);
        $plan = $this->planner->plan($this->engine, $before, $draft);
        self::assertNotSame([], $plan->statements, 'the draft should change something');
        $this->target->ddl()->execute($plan->statements, $plan->transactional);

        return $this->describe($draft->name);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function relational(): iterable
    {
        yield from TargetDatabase::relational();
    }

    #[DataProvider('relational')]
    public function testRenamesModifiesAddsAndDropsColumnsAndIndexesOnARealTable(string $env): void
    {
        $this->open($env);
        $table = $this->scratch();
        $this->createBase($table);
        $before = $this->describe($table);
        $idx = 'idx_name'.substr($table, -8);
        $draft = self::draftOf($before);

        $columns = [
            $draft->columns[0],
            new ColumnDraft('name', 'name', $this->varchar(80), false, null, false),
            new ColumnDraft('note', 'remark', 'text', true, null, false),
            new ColumnDraft(null, 'qty', $this->int(), false, '0', false),
        ];
        $indexes = [
            $draft->indexes[0]->primary ? $draft->indexes[0] : $draft->indexes[1],
            new IndexDraft(null, 'idx_qty'.substr($table, -8), ['qty'], false, false, null, null),
        ];
        $after = $this->apply($before, new TableDraft($table, $columns, $indexes, []));

        self::assertSame(['id', 'name', 'remark', 'qty'], array_map(static fn ($c): string => $c->name, $after->columns));
        self::assertStringContainsString('80', self::col($after, 'name')->type);
        self::assertStringContainsString('0', self::col($after, 'qty')->default ?? '');
        $names = array_map(static fn ($i): string => $i->name, $after->indexes);
        self::assertContains('idx_qty'.substr($table, -8), $names);
        self::assertNotContains($idx, $names);
        self::assertNotNull($after->primaryKey());
    }

    #[DataProvider('relational')]
    public function testChangesNullabilityDefaultAndAutoIncrementThenTheTableName(string $env): void
    {
        $this->open($env);
        $table = $this->scratch();
        $this->createBase($table);
        $before = $this->describe($table);
        $draft = self::draftOf($before);

        $after = $this->apply($before, new TableDraft($table, [
            $draft->columns[0],
            new ColumnDraft('name', 'name', self::col($before, 'name')->type, true, 'anonymous', false),
            new ColumnDraft('note', 'note', 'text', false, null, false),
        ], $draft->indexes, []));

        self::assertTrue($after->column('name')?->nullable);
        self::assertStringContainsString('anonymous', self::col($after, 'name')->default ?? '');
        self::assertFalse($after->column('note')?->nullable);

        $renamed = $this->scratch('tdb_ren');
        $final = $this->apply($after, self::draftOf($after)->withName($renamed));
        self::assertSame($renamed, $final->name);
    }

    #[DataProvider('relational')]
    public function testChangesThePrimaryKey(string $env): void
    {
        $this->open($env);
        $table = $this->scratch();
        $this->createBase($table);
        $before = $this->describe($table);
        $draft = self::draftOf($before);
        $indexes = array_map(static fn (IndexDraft $i): IndexDraft => $i->primary ? new IndexDraft($i->originalName, $i->name, ['id', 'name'], true, true, null, null) : $i, $draft->indexes);

        $after = $this->apply($before, $draft->withIndexes($indexes));

        self::assertSame(['id', 'name'], $after->primaryKey()?->columns);
    }

    #[DataProvider('relational')]
    public function testAddsThenDropsAUniqueIndexAndAForeignKey(string $env): void
    {
        $this->open($env);
        $parent = $this->scratch('tdb_par');
        $this->raw(Engine::PostgreSql === $this->engine
            ? \sprintf('CREATE TABLE %s (id integer NOT NULL PRIMARY KEY)', $this->q($parent))
            : \sprintf('CREATE TABLE %s (id int NOT NULL PRIMARY KEY) ENGINE=InnoDB', $this->q($parent)));
        $table = $this->scratch();
        $this->createBase($table);
        $before = $this->describe($table);
        $draft = self::draftOf($before);
        $suffix = substr($table, -8);

        $withBoth = $this->apply($before, new TableDraft(
            $table,
            $draft->columns,
            [...$draft->indexes, new IndexDraft(null, 'u_note'.$suffix, ['name'], true, false, null, null)],
            [new ForeignKeyDraft(null, 'fk_id'.$suffix, ['id'], $parent, ['id'], ReferentialAction::NoAction, ReferentialAction::Cascade)],
        ));

        self::assertCount(1, $withBoth->foreignKeys);
        self::assertSame(ReferentialAction::Cascade, $withBoth->foreignKeys[0]->onDelete);
        $unique = array_values(array_filter($withBoth->indexes, static fn ($i): bool => 'u_note'.$suffix === $i->name));
        self::assertTrue($unique[0]->unique ?? false);

        $without = $this->apply($withBoth, self::draftOf($withBoth)->withForeignKeys([])->withIndexes(array_values(array_filter(self::draftOf($withBoth)->indexes, static fn (IndexDraft $i): bool => 'u_note'.$suffix !== $i->name))));
        self::assertSame([], $without->foreignKeys);
        self::assertNotContains('u_note'.$suffix, array_map(static fn ($i): string => $i->name, $without->indexes));
    }

    #[DataProvider('relational')]
    public function testDropsAColumnAndThenTheWholeTable(string $env): void
    {
        $this->open($env);
        $table = $this->scratch();
        $this->createBase($table);
        $before = $this->describe($table);
        $draft = self::draftOf($before);

        $after = $this->apply($before, new TableDraft($table, [$draft->columns[0], $draft->columns[1]], $draft->indexes, []));
        self::assertNull($after->column('note'));

        \assert(null !== $this->target);
        $plan = $this->planner->planDrop($this->engine, $after);
        $this->target->ddl()->execute($plan->statements, $plan->transactional);
        $this->expectException(\Throwable::class);
        $this->describe($table);
    }

    public function testPostgreSqlRollsEverythingBackWhenOneStatementFails(): void
    {
        $this->open('TARGET_POSTGRES_URL');
        $table = $this->scratch();
        $this->createBase($table);
        $before = $this->describe($table);
        $draft = self::draftOf($before);
        $this->raw(\sprintf('INSERT INTO %s VALUES (1, %s, NULL)', $this->q($table), "'x'"));

        // La seconde instruction échoue (type incompatible avec les données) : la première, déjà exécutée, doit être annulée.
        try {
            $this->apply($before, new TableDraft($table, [
                $draft->columns[0],
                new ColumnDraft('name', 'name', 'integer', false, null, false),
                new ColumnDraft(null, 'added_first', 'text', true, null, false),
            ], $draft->indexes, []));
            self::fail('The type change must fail on data that is not a number.');
        } catch (SchemaChangeFailedException $e) {
            self::assertSame([], $e->applied, 'nothing stays applied after a failed transaction');
            self::assertNotSame('', $e->getMessage());
        }

        self::assertNull($this->describe($table)->column('added_first'));
        self::assertStringContainsString('character varying', self::col($this->describe($table), 'name')->type);
    }

    public function testMysqlReportsWhatWasAlreadyAppliedWhenALaterStatementFails(): void
    {
        $this->open('TARGET_MYSQL_URL');
        $table = $this->scratch();
        $this->createBase($table);
        $before = $this->describe($table);
        $draft = self::draftOf($before);

        try {
            $this->apply($before, new TableDraft($table, [
                ...$draft->columns,
                new ColumnDraft(null, 'first_ok', 'text', true, null, false),
                new ColumnDraft(null, 'second_bad', 'notatype', true, null, false),
            ], $draft->indexes, []));
            self::fail('An unknown type must be refused by the engine.');
        } catch (SchemaChangeFailedException $e) {
            self::assertCount(1, $e->applied);
            self::assertSame('first_ok', $e->applied[0]->subject);
            self::assertSame('second_bad', $e->failed->subject);
        }

        self::assertNotNull($this->describe($table)->column('first_ok'), 'MySQL commits each DDL: the first one stays');
    }

    public function testClickHouseColumnsIndexesNullabilityAndTable(): void
    {
        $this->open('TARGET_CLICKHOUSE_URL');
        $table = $this->scratch();
        $this->raw(\sprintf('CREATE TABLE %s (id UInt64, name String, note Nullable(String), INDEX idx_name name TYPE minmax GRANULARITY 1) ENGINE = MergeTree ORDER BY id', $this->q($table)));
        $before = $this->describe($table);
        $draft = self::draftOf($before);
        $byName = static fn (TableDraft $d, string $n): IndexDraft => array_values(array_filter($d->indexes, static fn (IndexDraft $i): bool => $i->name === $n))[0];

        $after = $this->apply($before, new TableDraft($table, [
            $draft->columns[0],
            new ColumnDraft('name', 'name', 'String', false, 'x', false),
            // Nullable(String) -> String : ClickHouse exige alors une valeur par défaut (« '' » = la chaîne vide).
            new ColumnDraft('note', 'remark', 'String', false, "''", false),
            new ColumnDraft(null, 'qty', 'UInt32', false, '0', false),
        ], [$byName($draft, 'ORDER BY'), new IndexDraft(null, 'idx_qty', ['qty'], false, false, 'minmax', 1)], []));

        self::assertSame(['id', 'name', 'remark', 'qty'], array_map(static fn ($c): string => $c->name, $after->columns));
        self::assertFalse($after->column('remark')?->nullable, 'Nullable(String) became String');
        self::assertStringContainsString('x', self::col($after, 'name')->default ?? '');
        $names = array_map(static fn ($i): string => $i->name, $after->indexes);
        self::assertContains('idx_qty', $names);
        self::assertNotContains('idx_name', $names);

        $renamed = $this->scratch('tdb_ren');
        $final = $this->apply($after, self::draftOf($after)->withName($renamed));
        self::assertSame($renamed, $final->name);
    }

    public function testClickHouseRefusesToChangeTheSortKey(): void
    {
        $this->open('TARGET_CLICKHOUSE_URL');
        $table = $this->scratch();
        $this->raw(\sprintf('CREATE TABLE %s (id UInt64, name String) ENGINE = MergeTree ORDER BY id', $this->q($table)));
        $before = $this->describe($table);
        $draft = self::draftOf($before);

        $this->expectException(\App\Core\Schema\Change\Exception\InvalidSchemaChangeException::class);

        $this->planner->plan(Engine::ClickHouse, $before, $draft->withIndexes([]));
    }
}
