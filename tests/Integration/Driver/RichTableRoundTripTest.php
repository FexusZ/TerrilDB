<?php

declare(strict_types=1);

namespace App\Tests\Integration\Driver;

use App\Core\Connection\Engine;
use App\Core\Driver\Exception\TargetConnectionFailedException;
use App\Core\Driver\TargetConnection;
use App\Core\Guard\StatementKind;
use App\Core\Query\QueryOptions;
use App\Core\Schema\Change\ColumnDraft;
use App\Core\Schema\Change\DdlStatement;
use App\Core\Schema\Change\Exception\InvalidSchemaChangeException;
use App\Core\Schema\Change\Exception\SchemaChangeFailedException;
use App\Core\Schema\Change\Exception\UnsupportedSchemaChangeException;
use App\Core\Schema\Change\ForeignKeyDraft;
use App\Core\Schema\Change\IndexDraft;
use App\Core\Schema\Change\SchemaChangePlanner;
use App\Core\Schema\Change\SchemaChangeService;
use App\Core\Schema\Change\TableDraft;
use App\Core\Schema\TableDefinition;
use App\Driver\DbalConnector;
use App\Driver\MySql\MySqlDriver;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Aller-retour sur une table « riche » (colonne générée stockée, index invisible / ignoré, CHECK de table, ENUM, SET,
 * ON UPDATE CURRENT_TIMESTAMP, FULLTEXT) : éditer AUTRE chose ne doit rien lui faire perdre, et un brouillon identique à la table
 * ne produit aucune instruction. Issue acceptable : préservé, ou refusé (par TerrilDB ou par le moteur). Table jetable.
 */
#[Group('integration')]
final class RichTableRoundTripTest extends TestCase
{
    private ?TargetConnection $target = null;
    private ?TargetConnection $reader = null;
    private Engine $engine;
    private string $table = '';

    protected function tearDown(): void
    {
        if ('' !== $this->table) {
            try {
                $this->raw(\sprintf('DROP TABLE IF EXISTS `%s`', $this->table));
            } catch (\Throwable) {
            }
        }
        $this->target?->close();
        $this->reader?->close();
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function edits(): iterable
    {
        foreach (TargetDatabase::relational() as $engine => [$env]) {
            if (str_contains($engine, 'Postgre')) {
                continue;
            }
            foreach (['nothing', 'rename_plain', 'rename_checked', 'add_index', 'add_column', 'drop_plain_column', 'nullable_enum', 'nullable_set', 'nullable_timestamp', 'retype_plain', 'drop_index', 'rename_index', 'enum_remove', 'set_remove'] as $edit) {
                yield $engine.' / '.$edit => [$env, $edit];
            }
        }
    }

    #[DataProvider('edits')]
    public function testEditingSomethingElseLosesNothing(string $env, string $edit): void
    {
        $this->open($env);
        $target = $this->target;
        \assert(null !== $target);
        $this->create();
        $before = $this->marks();
        $current = $target->introspector()->describeTable($this->table);
        $service = new SchemaChangeService();

        if ('nothing' === $edit) {
            $preview = $service->preview($target, $this->table, self::draftOf($current), SchemaChangePlanner::fingerprint($current));
            self::assertSame([], array_map(static fn (DdlStatement $s): string => $s->sql, $preview->plan->statements), 'an identical draft must produce no SQL');
            self::assertSame($before, $this->marks());

            return;
        }

        try {
            $service->apply($target, $this->table, $this->edited(self::draftOf($current), $edit), SchemaChangePlanner::fingerprint($current), true);
        } catch (InvalidSchemaChangeException|UnsupportedSchemaChangeException|SchemaChangeFailedException) {
            // Refus (de TerrilDB ou du moteur) : rien n'a été dégradé.
        }

        $after = $this->marks();
        foreach ($before as $key => $count) {
            self::assertSame($count, $after[$key] ?? null, \sprintf('"%s" was lost or changed by "%s"', $key, $edit));
        }
    }

    private function edited(TableDraft $draft, string $edit): TableDraft
    {
        $columns = $draft->columns;
        $replace = static fn (string $name, \Closure $change): array => self::replacing($columns, $name, $change);

        return match ($edit) {
            'rename_plain' => new TableDraft(
                $draft->name,
                $replace('name', static fn (ColumnDraft $c): ColumnDraft => new ColumnDraft($c->originalName, 'label', $c->type, $c->nullable, $c->default, false)),
                array_map(static fn (IndexDraft $i): IndexDraft => 'idx_plain' === $i->name ? new IndexDraft($i->originalName, $i->name, ['label'], $i->unique, false, null, null) : $i, $draft->indexes),
                $draft->foreignKeys,
            ),
            'rename_checked' => $draft->withColumns($replace('qty', static fn (ColumnDraft $c): ColumnDraft => new ColumnDraft($c->originalName, 'quantity', $c->type, $c->nullable, $c->default, false))),
            'add_index' => $draft->withIndexes([...$draft->indexes, new IndexDraft(null, 'idx_added', ['name', 'qty'], false, false, null, null)]),
            'add_column' => $draft->withColumns([...$columns, new ColumnDraft(null, 'zz_added', 'int', true, null, false)]),
            'drop_plain_column' => $draft->withColumns(array_values(array_filter($columns, static fn (ColumnDraft $c): bool => 'spare' !== $c->name))),
            'nullable_enum' => $draft->withColumns($replace('kind', static fn (ColumnDraft $c): ColumnDraft => new ColumnDraft($c->originalName, $c->name, $c->type, true, $c->default, false))),
            'nullable_set' => $draft->withColumns($replace('tags', static fn (ColumnDraft $c): ColumnDraft => new ColumnDraft($c->originalName, $c->name, $c->type, false, "'p'", false))),
            'nullable_timestamp' => $draft->withColumns($replace('ts', static fn (ColumnDraft $c): ColumnDraft => new ColumnDraft($c->originalName, $c->name, $c->type, true, null, false))),
            'retype_plain' => $draft->withColumns($replace('name', static fn (ColumnDraft $c): ColumnDraft => new ColumnDraft($c->originalName, $c->name, 'varchar(80)', $c->nullable, $c->default, false))),
            'drop_index' => $draft->withIndexes(array_values(array_filter($draft->indexes, static fn (IndexDraft $i): bool => 'idx_plain' !== $i->name))),
            'rename_index' => $draft->withIndexes(array_map(static fn (IndexDraft $i): IndexDraft => 'idx_plain' === $i->name ? new IndexDraft($i->originalName, 'idx_plain2', $i->columns, $i->unique, false, null, null) : $i, $draft->indexes)),
            'enum_remove' => $draft->withColumns($replace('kind', static fn (ColumnDraft $c): ColumnDraft => new ColumnDraft($c->originalName, $c->name, "enum('x','y')", $c->nullable, $c->default, false))),
            'set_remove' => $draft->withColumns($replace('tags', static fn (ColumnDraft $c): ColumnDraft => new ColumnDraft($c->originalName, $c->name, "set('p','q')", $c->nullable, $c->default, false))),
            default => throw new \LogicException('Unknown edit.'),
        };
    }

    /**
     * @param list<ColumnDraft>                  $columns
     * @param \Closure(ColumnDraft): ColumnDraft $change
     *
     * @return list<ColumnDraft>
     */
    private static function replacing(array $columns, string $name, \Closure $change): array
    {
        return array_map(static fn (ColumnDraft $c): ColumnDraft => $c->name === $name ? $change($c) : $c, $columns);
    }

    /**
     * Ce que le moteur dit de la table (SHOW CREATE TABLE), compté particularité par particularité.
     *
     * @return array<string, int>
     */
    private function marks(): array
    {
        $create = (string) ($this->rows(\sprintf('SHOW CREATE TABLE `%s`', $this->table))[0][1] ?? '');
        $maria = Engine::MariaDb === $this->engine;

        return [
            'generated' => substr_count($create, ' AS ('),
            'check' => substr_count(strtoupper($create), 'CHECK ('),
            'enum' => substr_count($create, 'enum('),
            'set' => substr_count($create, 'set('),
            'on update' => substr_count(strtoupper($create), 'ON UPDATE CURRENT_TIMESTAMP'),
            'fulltext' => substr_count($create, 'FULLTEXT KEY'),
            'hidden index' => substr_count($create, $maria ? 'IGNORED' : 'INVISIBLE'),
            'enum values' => (int) preg_match("/enum\\('x','y','z'\\)/", $create),
            'set values' => (int) preg_match("/set\\('p','q','r'\\)/", $create),
            'data' => (int) ($this->rows(\sprintf("SELECT count(*) FROM `%s` WHERE kind = 'z' AND tags = 'q,r'", $this->table))[0][0] ?? 0),
        ];
    }

    private function create(): void
    {
        $this->table = 'tdb_rich_'.bin2hex(random_bytes(4));
        $maria = Engine::MariaDb === $this->engine;
        $this->raw(\sprintf(
            "CREATE TABLE `%s` (
                id int NOT NULL PRIMARY KEY,
                a int NOT NULL,
                g int GENERATED ALWAYS AS (a * 2) STORED,
                name varchar(40) NOT NULL,
                title varchar(60) NULL,
                kind enum('x','y','z') NOT NULL DEFAULT 'x',
                tags set('p','q','r') NULL,
                ts timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                qty int NULL,
                note varchar(20) NULL,
                spare int NULL,
                CONSTRAINT chk_qty CHECK (qty IS NULL OR qty >= 0),
                KEY idx_g (g),
                KEY idx_plain (name),
                KEY idx_hidden (note) %s,
                FULLTEXT KEY ft_title (title)
                %s
            ) ENGINE=InnoDB",
            $this->table,
            $maria ? 'IGNORED' : 'INVISIBLE',
            $maria ? '' : ', KEY idx_fn ((a + 1))',
        ));
        $this->raw(\sprintf("INSERT INTO `%s` (id, a, name, kind, tags, qty) VALUES (1, 1, 'n', 'z', 'q,r', 3)", $this->table));
    }

    private function open(string $env): void
    {
        $this->engine = TargetDatabase::engine($env);
        $parameters = TargetDatabase::parameters($env);
        $connect = fn (): TargetConnection => (new MySqlDriver(new DbalConnector()))->connect($this->engine, $parameters);
        try {
            $this->target = $connect();
            $this->reader = $connect();
        } catch (TargetConnectionFailedException) {
            self::markTestSkipped('The target is unreachable (docker compose --profile targets up -d --wait).');
        }
    }

    private function raw(string $sql): void
    {
        \assert(null !== $this->target);
        $this->target->ddl()->execute([new DdlStatement($sql, 'raw', 'raw', false)], false);
    }

    /**
     * @return list<list<bool|float|int|string|null>>
     */
    private function rows(string $sql): array
    {
        \assert(null !== $this->reader);

        return $this->reader->runner()->run($sql, StatementKind::Read, new QueryOptions())->rows;
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
}
