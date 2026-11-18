<?php

declare(strict_types=1);

namespace App\Tests\Integration\Driver;

use App\Core\Connection\Engine;
use App\Core\Driver\Exception\TargetConnectionFailedException;
use App\Core\Driver\TargetConnection;
use App\Core\Schema\Change\Algorithm\AlterAlgorithm;
use App\Core\Schema\Change\Algorithm\AlterAlgorithmAdvisor;
use App\Core\Schema\Change\ColumnDraft;
use App\Core\Schema\Change\DdlStatement;
use App\Core\Schema\Change\ForeignKeyDraft;
use App\Core\Schema\Change\IndexDraft;
use App\Core\Schema\Change\SchemaChangePlanner;
use App\Core\Schema\Change\TableDraft;
use App\Core\Schema\ColumnDefinition;
use App\Core\Schema\ForeignKeyDefinition;
use App\Core\Schema\IndexDefinition;
use App\Core\Schema\ReferentialAction;
use App\Core\Schema\TableDefinition;
use App\Driver\DbalConnector;
use App\Driver\PostgreSql\PostgreSqlDriver;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Les règles PostgreSQL vérifiées sur PostgreSQL 18, sur une table jetable `tdb_alg_<hex>` : l'instruction du vrai planificateur
 * est jouée dans une transaction qu'on ANNULE, et on compare le fichier de la table (`pg_class.relfilenode`, qui change à chaque
 * réécriture) avant et après, puis le verrou pris sur la table (`pg_locks`) : un verrou qui entre en conflit avec une écriture
 * (ACCESS EXCLUSIVE, SHARE ROW EXCLUSIVE, SHARE) justifie « bloque les écritures ». Ce qui n'est pas vérifié reste « unknown ».
 */
#[Group('integration')]
final class AlterAlgorithmPostgreSqlTest extends TestCase
{
    private const string ENV = 'TARGET_POSTGRES_URL';
    private const array WRITE_BLOCKING_LOCKS = ['AccessExclusiveLock', 'ShareRowExclusiveLock', 'ShareLock'];

    private string $table = '';
    private string $parent = '';
    private ?\PDO $pdo = null;
    private ?TargetConnection $target = null;

    protected function setUp(): void
    {
        $hex = bin2hex(random_bytes(4));
        $this->table = 'tdb_alg_'.$hex;
        $this->parent = 'tdb_alg_p_'.$hex;
        try {
            $this->target = (new PostgreSqlDriver(new DbalConnector()))->connect(Engine::PostgreSql, TargetDatabase::parameters(self::ENV));
        } catch (TargetConnectionFailedException) {
            self::markTestSkipped(self::ENV.' is unreachable (docker compose --profile targets up -d --wait).');
        }
        $this->pdo = LockPeer::connect(self::ENV);
        $pdo = $this->pdo;
        $pdo->exec(\sprintf('CREATE TABLE %s (id integer PRIMARY KEY)', $this->parent));
        $pdo->exec(\sprintf('INSERT INTO %s SELECT generate_series(0, 60)', $this->parent));
        $pdo->exec(\sprintf('CREATE TABLE %s (id integer NOT NULL PRIMARY KEY, name character varying(40) NOT NULL, qty integer, note text, other integer, CONSTRAINT %s_fk FOREIGN KEY (other) REFERENCES %s (id))', $this->table, $this->table, $this->parent));
        $pdo->exec(\sprintf('CREATE INDEX %s_name ON %s (name)', $this->table, $this->table));
        $pdo->exec(\sprintf("INSERT INTO %s SELECT i, 'n' || i, i, 'x', 1 FROM generate_series(1, 50) i", $this->table));
    }

    protected function tearDown(): void
    {
        if (null !== $this->pdo) {
            try {
                $this->pdo->exec('ROLLBACK');
            } catch (\Throwable) {
            }
            $this->pdo->exec(\sprintf('DROP TABLE IF EXISTS %s', $this->table));
            $this->pdo->exec(\sprintf('DROP TABLE IF EXISTS %s', $this->parent));
        }
        $this->target?->close();
    }

    /**
     * @return iterable<string, array{string, \Closure(TableDraft): TableDraft, AlterAlgorithm, ?bool}>
     */
    public static function scenarios(): iterable
    {
        $I = AlterAlgorithm::Instant;
        $P = AlterAlgorithm::Inplace;
        $R = AlterAlgorithm::Rewrite;
        $U = AlterAlgorithm::Unknown;

        yield 'add a nullable column' => ['add_column', static fn (TableDraft $d): TableDraft => self::add($d, 'integer', true, null), $I, null];
        yield 'add a NOT NULL column with a constant default' => ['add_column', static fn (TableDraft $d): TableDraft => self::add($d, 'integer', false, '0'), $I, null];
        yield 'add a column with a text default' => ['add_column', static fn (TableDraft $d): TableDraft => self::add($d, 'text', true, "'a'"), $I, null];
        yield 'add a column default now()' => ['add_column', static fn (TableDraft $d): TableDraft => self::add($d, 'timestamp', true, 'now()'), $I, null];
        yield 'add a boolean column default true' => ['add_column', static fn (TableDraft $d): TableDraft => self::add($d, 'boolean', true, 'true'), $I, null];
        yield 'add a column with a volatile default' => ['add_column', static fn (TableDraft $d): TableDraft => self::add($d, 'uuid', true, 'gen_random_uuid()'), $R, true];
        yield 'add an identity column is not claimed' => ['add_column', static fn (TableDraft $d): TableDraft => self::add($d, 'integer', false, null, true), $U, null];
        yield 'drop a column' => ['drop_column', static fn (TableDraft $d): TableDraft => self::dropColumn($d, 'note'), $I, null];
        yield 'rename a column' => ['rename_column', static fn (TableDraft $d): TableDraft => self::alter($d, 'qty', 'integer', true, null, 'quantity'), $I, null];
        yield 'set NOT NULL' => ['modify_column', static fn (TableDraft $d): TableDraft => self::alter($d, 'qty', 'integer', false, null), $P, true];
        yield 'drop NOT NULL' => ['modify_column', static fn (TableDraft $d): TableDraft => self::alter($d, 'name', 'character varying(40)', true, null), $I, null];
        yield 'set a default' => ['modify_column', static fn (TableDraft $d): TableDraft => self::alter($d, 'qty', 'integer', true, '5'), $I, null];
        yield 'integer to bigint' => ['modify_column', static fn (TableDraft $d): TableDraft => self::alter($d, 'qty', 'bigint', true, null), $R, true];
        yield 'integer to smallint' => ['modify_column', static fn (TableDraft $d): TableDraft => self::alter($d, 'qty', 'smallint', true, null), $R, true];
        yield 'integer to text' => ['modify_column', static fn (TableDraft $d): TableDraft => self::alter($d, 'qty', 'text', true, null), $R, true];
        yield 'integer to varchar' => ['modify_column', static fn (TableDraft $d): TableDraft => self::alter($d, 'qty', 'character varying(20)', true, null), $R, true];
        yield 'grow an indexed varchar' => ['modify_column', static fn (TableDraft $d): TableDraft => self::alter($d, 'name', 'character varying(80)', false, null), $I, null];
        yield 'varchar to text' => ['modify_column', static fn (TableDraft $d): TableDraft => self::alter($d, 'name', 'text', false, null), $I, null];
        yield 'shrink a varchar is not claimed' => ['modify_column', static fn (TableDraft $d): TableDraft => self::alter($d, 'name', 'character varying(10)', false, null), $U, null];
        yield 'text to jsonb is not claimed' => ['modify_column', static fn (TableDraft $d): TableDraft => self::alter($d, 'note', 'jsonb', true, null), $U, null];
        yield 'add an index' => ['add_index', static fn (TableDraft $d): TableDraft => self::index($d, new IndexDraft(null, 'idx_qty', ['qty'], false, false, null, null)), $P, true];
        yield 'add a unique index' => ['add_index', static fn (TableDraft $d): TableDraft => self::index($d, new IndexDraft(null, 'uq_qty', ['qty'], true, false, null, null)), $P, true];
        yield 'drop an index' => ['drop_index', static fn (TableDraft $d): TableDraft => self::withoutIndex($d, 'name'), $I, null];
        yield 'add a foreign key' => ['add_foreign_key', static fn (TableDraft $d): TableDraft => self::foreignKey($d, true), $P, true];
        yield 'drop a foreign key' => ['drop_foreign_key', static fn (TableDraft $d): TableDraft => self::foreignKey($d, false), $I, null];
        yield 'drop the primary key is not claimed' => ['drop_primary_key', static fn (TableDraft $d): TableDraft => self::withoutIndex($d, 'primary'), $U, null];
        yield 'rename the table' => ['rename_table', static fn (TableDraft $d): TableDraft => new TableDraft($d->name.'r', $d->columns, $d->indexes, $d->foreignKeys), $I, null];
    }

    /**
     * @param \Closure(TableDraft): TableDraft $change
     */
    #[DataProvider('scenarios')]
    public function testTheAdvisorNeverContradictsPostgreSql(string $operation, \Closure $change, AlterAlgorithm $expected, ?bool $blocksWrites): void
    {
        $pdo = $this->pdo ?? throw new \LogicException('Not opened.');
        $target = $this->target ?? throw new \LogicException('Not opened.');
        $current = $target->introspector()->describeTable($this->table);
        $draft = $this->bound($change($this->draftOf($current)));
        $plan = (new SchemaChangePlanner())->plan(Engine::PostgreSql, $current, $draft);
        $advice = (new AlterAlgorithmAdvisor())->advise(Engine::PostgreSql, $target->version(), $current, $draft, $plan);

        $index = null;
        foreach ($plan->statements as $position => $statement) {
            if ($statement->operation === $operation) {
                $index = $position;
                break;
            }
        }
        self::assertNotNull($index, 'the plan should contain a '.$operation.' statement: '.implode(' | ', array_map(static fn (DdlStatement $s): string => $s->sql, $plan->statements)));
        $verdict = $advice[$index];
        self::assertSame($expected, $verdict->algorithm, $plan->statements[$index]->sql.' (reason: '.($verdict->reason ?? 'none').')');
        if (AlterAlgorithm::Unknown === $expected) {
            self::assertNull($verdict->blocksWrites);
            self::assertNull($verdict->copiesTable);

            return;
        }

        $pdo->exec('BEGIN');
        try {
            foreach (\array_slice($plan->statements, 0, $index) as $before) {
                $pdo->exec($before->sql);
            }
            $before = $this->fileOf($this->table);
            $pdo->exec($plan->statements[$index]->sql);
            $after = $this->fileOf($draft->name);
            $modes = $this->lockModes($draft->name);
        } finally {
            $pdo->exec('ROLLBACK');
        }

        $rewrote = $before !== $after;
        $why = $plan->statements[$index]->sql.' file '.$before.' -> '.$after.' locks '.implode(',', $modes);
        self::assertSame(AlterAlgorithm::Rewrite === $expected, $rewrote, ($rewrote ? 'rewrote' : 'did not rewrite').' the table: '.$why);
        self::assertSame($rewrote, $verdict->copiesTable, $why);
        self::assertSame($blocksWrites, $verdict->blocksWrites, $why);
        if (true === $blocksWrites) {
            self::assertNotSame([], array_intersect($modes, self::WRITE_BLOCKING_LOCKS), 'the lock conflicts with a write: '.$why);
        }
        if (AlterAlgorithm::Rewrite === $expected) {
            self::assertGreaterThan(0, $verdict->estimatedExtraBytes ?? 0);
        } else {
            self::assertNull($verdict->estimatedExtraBytes);
        }
    }

    public function testSettingNotNullScansTheTable(): void
    {
        $pdo = $this->pdo ?? throw new \LogicException('Not opened.');
        $pdo->exec(\sprintf('UPDATE %s SET qty = NULL WHERE id = 7', $this->table));

        try {
            $pdo->exec(\sprintf('ALTER TABLE %s ALTER COLUMN qty SET NOT NULL', $this->table));
            self::fail('The scan should have found the NULL.');
        } catch (\PDOException $e) {
            self::assertSame('23502', $e->errorInfo[0] ?? null, 'a scan of the data happened: it is not a metadata-only change, which is why SET NOT NULL is "inplace" and blocking');
        }
    }

    private function fileOf(string $table): string
    {
        $pdo = $this->pdo ?? throw new \LogicException('Not opened.');
        $statement = $pdo->query(\sprintf("SELECT relfilenode FROM pg_class WHERE oid = '%s'::regclass", $table));
        $value = false === $statement ? false : $statement->fetchColumn();

        return \is_scalar($value) ? (string) $value : '';
    }

    /**
     * @return list<string>
     */
    private function lockModes(string $table): array
    {
        $pdo = $this->pdo ?? throw new \LogicException('Not opened.');
        $statement = $pdo->query(\sprintf("SELECT mode FROM pg_locks WHERE relation = '%s'::regclass AND pid = pg_backend_pid() AND granted", $table));
        $rows = false === $statement ? [] : $statement->fetchAll(\PDO::FETCH_COLUMN);

        return array_values(array_map(static fn (mixed $m): string => \is_string($m) ? $m : '', $rows));
    }

    private function draftOf(TableDefinition $table): TableDraft
    {
        return new TableDraft(
            $table->name,
            array_map(static fn (ColumnDefinition $c): ColumnDraft => new ColumnDraft($c->name, $c->name, $c->type, $c->nullable, $c->default, $c->autoIncrement), $table->columns),
            array_map(static fn (IndexDefinition $i): IndexDraft => new IndexDraft($i->name, $i->name, $i->columns, $i->unique, $i->primary, null, null), $table->indexes),
            array_map(static fn (ForeignKeyDefinition $f): ForeignKeyDraft => new ForeignKeyDraft($f->name, $f->name, $f->columns, $f->referencedTable, $f->referencedColumns, $f->onUpdate, $f->onDelete), $table->foreignKeys),
        );
    }

    /**
     * Le nom de la table renommée et celui de la table parente (qu'on ne connaît pas à l'avance) deviennent les vrais noms.
     */
    private function bound(TableDraft $draft): TableDraft
    {
        $name = str_ends_with($draft->name, 'r') ? $this->table.'r' : $this->table;
        $foreignKeys = array_map(fn (ForeignKeyDraft $f): ForeignKeyDraft => 'PARENT' === $f->referencedTable ? new ForeignKeyDraft($f->originalName, $f->name.substr($this->table, -8), $f->columns, $this->parent, $f->referencedColumns, $f->onUpdate, $f->onDelete) : $f, $draft->foreignKeys);

        return new TableDraft($name, $draft->columns, $draft->indexes, $foreignKeys);
    }

    private static function add(TableDraft $d, string $type, bool $nullable, ?string $default, bool $auto = false): TableDraft
    {
        return new TableDraft($d->name, [...$d->columns, new ColumnDraft(null, 'x', $type, $nullable, $default, $auto)], $d->indexes, $d->foreignKeys);
    }

    private static function dropColumn(TableDraft $d, string $name): TableDraft
    {
        return new TableDraft($d->name, array_values(array_filter($d->columns, static fn (ColumnDraft $c): bool => $c->name !== $name)), $d->indexes, $d->foreignKeys);
    }

    private static function alter(TableDraft $d, string $column, string $type, bool $nullable, ?string $default, ?string $newName = null): TableDraft
    {
        return new TableDraft($d->name, array_map(static fn (ColumnDraft $c): ColumnDraft => $c->name === $column ? new ColumnDraft($c->originalName, $newName ?? $c->name, $type, $nullable, $default, false) : $c, $d->columns), $d->indexes, $d->foreignKeys);
    }

    private static function index(TableDraft $d, IndexDraft $new): TableDraft
    {
        return new TableDraft($d->name, $d->columns, [...$d->indexes, $new], $d->foreignKeys);
    }

    private static function withoutIndex(TableDraft $d, string $marker): TableDraft
    {
        return new TableDraft($d->name, $d->columns, array_values(array_filter($d->indexes, static fn (IndexDraft $i): bool => 'primary' === $marker ? !$i->primary : !str_ends_with($i->name, '_name'))), $d->foreignKeys);
    }

    private static function foreignKey(TableDraft $d, bool $add): TableDraft
    {
        if (!$add) {
            return new TableDraft($d->name, $d->columns, $d->indexes, []);
        }

        return new TableDraft($d->name, $d->columns, $d->indexes, [...$d->foreignKeys, new ForeignKeyDraft(null, 'fk_qty', ['qty'], 'PARENT', ['id'], ReferentialAction::Restrict, ReferentialAction::Restrict)]);
    }
}
