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
use App\Driver\PostgreSql\PostgreSqlDriver;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Fidélité de l'éditeur de structure : sur une table qui porte une particularité que le modèle ne sait pas dire (colonne générée,
 * invisible, ON UPDATE, COMPRESSED, index invisible, partitions, versionnement), éditer AUTRE chose, ou la colonne / l'index
 * concerné, ne doit jamais la faire disparaître en silence. Issue acceptable : elle est préservée, ou l'opération est refusée
 * (par TerrilDB, ou par le moteur). Tables jetables, jamais le schéma partagé.
 */
#[Group('integration')]
final class StructureFidelityTest extends TestCase
{
    private ?TargetConnection $target = null;
    /** Connexion à part pour relire : le runner laisse la session en lecture seule, ce qui bloquerait le DDL. */
    private ?TargetConnection $reader = null;
    private Engine $engine;

    /** @var list<string> */
    private array $tables = [];

    protected function tearDown(): void
    {
        foreach (array_reverse($this->tables) as $table) {
            try {
                $this->raw(\sprintf('DROP TABLE IF EXISTS %s', $this->q($table)));
            } catch (\Throwable) {
            }
        }
        $this->target?->close();
        $this->reader?->close();
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function cases(): iterable
    {
        foreach (TargetDatabase::relational() as $engine => [$env]) {
            foreach (['generated', 'invisible', 'on_update', 'compressed', 'invisible_index', 'index_options', 'check', 'partitions', 'versioning', 'period_columns'] as $case) {
                yield $engine.' / '.$case => [$env, $case];
            }
        }
    }

    #[DataProvider('cases')]
    public function testAddingAColumnKeepsTheParticularity(string $env, string $case): void
    {
        $this->open($env);
        $table = $this->create($case);
        $before = $this->mark($table, $case);
        self::assertGreaterThan(0, $before, 'the scratch table should carry the particularity');

        $this->editKeepingTheParticularity($table, static fn (TableDraft $d): TableDraft => $d->withColumns([
            ...$d->columns,
            new ColumnDraft(null, 'zz_added', 'int', true, null, false),
        ]));

        self::assertSame($before, $this->mark($table, $case));
    }

    #[DataProvider('cases')]
    public function testEditingTheConcernedColumnOrIndexNeverLosesTheParticularity(string $env, string $case): void
    {
        $this->open($env);
        $table = $this->create($case);
        $before = $this->mark($table, $case);

        $this->editKeepingTheParticularity($table, fn (TableDraft $d): TableDraft => $this->touch($d, $case));

        self::assertSame($before, $this->mark($table, $case));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function locked(): iterable
    {
        foreach (TargetDatabase::relational() as $engine => [$env]) {
            yield $engine.' / generated' => [$env, 'generated'];
            if (!str_contains($engine, 'Postgre')) {
                yield $engine.' / invisible_index' => [$env, 'invisible_index'];
                yield $engine.' / index_options' => [$env, 'index_options'];
            }
            if (str_contains($engine, 'MariaDB')) {
                // MySQL garde la contrainte d'une colonne au MODIFY ; MariaDB la perd.
                yield $engine.' / check' => [$env, 'check'];
            }
        }
    }

    /**
     * Redéfinir ce que le modèle ne sait pas redire est refusé par TerrilDB lui-même (code stable), avant d'écrire quoi que ce soit.
     */
    #[DataProvider('locked')]
    public function testRedefiningWhatCannotBeRestatedIsRefusedBeforeBeingSent(string $env, string $case): void
    {
        $this->open($env);
        $table = $this->create($case);
        \assert(null !== $this->target);
        $before = $this->mark($table, $case);
        $current = $this->target->introspector()->describeTable($table);

        $this->expectException(UnsupportedSchemaChangeException::class);
        try {
            (new SchemaChangeService())->preview($this->target, $table, $this->touch(self::draftOf($current), $case), SchemaChangePlanner::fingerprint($current));
        } finally {
            self::assertSame($before, $this->mark($table, $case));
        }
    }

    /**
     * Applique l'édition, ou constate un refus : de TerrilDB (InvalidSchemaChangeException), ou du moteur
     * (SchemaChangeFailedException). Le test appelant relit ensuite la particularité.
     *
     * @param callable(TableDraft): TableDraft $edit
     */
    private function editKeepingTheParticularity(string $table, callable $edit): void
    {
        \assert(null !== $this->target);
        $service = new SchemaChangeService();
        $current = $this->target->introspector()->describeTable($table);
        $draft = $edit(self::draftOf($current));
        try {
            $service->apply($this->target, $table, $draft, SchemaChangePlanner::fingerprint($current), true);
        } catch (InvalidSchemaChangeException|UnsupportedSchemaChangeException|SchemaChangeFailedException) {
            // Refus : rien n'a été dégradé.
            $this->addToAssertionCount(1);
        }
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

    private function touch(TableDraft $draft, string $case): TableDraft
    {
        /** @var list<ColumnDraft> $columns */
        $columns = $draft->columns;
        $varchar = Engine::PostgreSql === $this->engine ? 'character varying(80)' : 'varchar(80)';
        switch ($case) {
            case 'generated':
                $columns = self::replacing($columns, 'g', static fn (ColumnDraft $c): ColumnDraft => new ColumnDraft($c->originalName, $c->name, 'bigint', $c->nullable, $c->default, false));
                break;
            case 'invisible':
                $columns = self::replacing($columns, 'hid', static fn (ColumnDraft $c): ColumnDraft => new ColumnDraft($c->originalName, $c->name, 'bigint', $c->nullable, $c->default, false));
                break;
            case 'on_update':
                $columns = self::replacing($columns, 'ts', static fn (ColumnDraft $c): ColumnDraft => new ColumnDraft($c->originalName, $c->name, $c->type, true, $c->default, false));
                break;
            case 'compressed':
                $columns = self::replacing($columns, 'payload', static fn (ColumnDraft $c): ColumnDraft => new ColumnDraft($c->originalName, $c->name, $c->type, false, $c->default, false));
                break;
            case 'invisible_index':
                $indexes = array_map(static fn (IndexDraft $i): IndexDraft => 'idx_inv' === $i->name ? new IndexDraft($i->originalName, $i->name, ['name', 'qty'], false, false, null, null) : $i, $draft->indexes);

                return $draft->withIndexes($indexes);
            case 'index_options':
                $indexes = array_map(static fn (IndexDraft $i): IndexDraft => match ($i->name) {
                    'ft_title' => new IndexDraft($i->originalName, $i->name, ['title', 'name'], false, false, null, null),
                    'idx_pref', 'idx_desc' => new IndexDraft($i->originalName, $i->name, [...$i->columns, 'id'], false, false, null, null),
                    'idx_fn' => new IndexDraft($i->originalName, $i->name, [...$i->columns, 'id'], false, false, null, null),
                    'idx_sp' => new IndexDraft($i->originalName, $i->name, [...$i->columns, 'id'], false, false, null, null),
                    default => $i,
                }, $draft->indexes);

                return $draft->withIndexes($indexes);
            case 'check':
                $columns = self::replacing($columns, 'qty', static fn (ColumnDraft $c): ColumnDraft => new ColumnDraft($c->originalName, $c->name, 'bigint', $c->nullable, $c->default, false));
                break;
            case 'partitions':
            case 'versioning':
                $columns = self::replacing($columns, 'name', static fn (ColumnDraft $c): ColumnDraft => new ColumnDraft($c->originalName, $c->name, $varchar, $c->nullable, $c->default, false));
                break;
            case 'period_columns':
                $columns = self::replacing($columns, 'row_start', static fn (ColumnDraft $c): ColumnDraft => new ColumnDraft($c->originalName, $c->name, 'timestamp(3)', $c->nullable, $c->default, false));
                break;
        }

        return $draft->withColumns($columns);
    }

    /**
     * Crée la table jetable du cas. Cas sans objet sur le moteur : test sauté.
     */
    private function create(string $case): string
    {
        $table = $this->tables[] = 'tdb_fid_'.bin2hex(random_bytes(4));
        $t = $this->q($table);
        $pg = Engine::PostgreSql === $this->engine;
        $maria = Engine::MariaDb === $this->engine;

        switch ($case) {
            case 'generated':
                if ($pg) {
                    $this->raw(\sprintf('CREATE TABLE %s (id integer NOT NULL PRIMARY KEY, a integer NOT NULL, g integer GENERATED ALWAYS AS (a * 2) STORED, v integer GENERATED ALWAYS AS (a + 1) VIRTUAL, note text)', $t));
                } else {
                    $this->raw(\sprintf('CREATE TABLE %s (id int NOT NULL PRIMARY KEY, a int NOT NULL, g int GENERATED ALWAYS AS (a * 2) VIRTUAL, v int GENERATED ALWAYS AS (a + 1) STORED, note text NULL) ENGINE=InnoDB', $t));
                }
                break;
            case 'invisible':
                $this->onlyMySqlFamily();
                $this->raw(\sprintf('CREATE TABLE %s (id int NOT NULL PRIMARY KEY, name varchar(40) NOT NULL, hid int NULL INVISIBLE, note text NULL) ENGINE=InnoDB', $t));
                break;
            case 'on_update':
                $this->onlyMySqlFamily();
                $this->raw(\sprintf('CREATE TABLE %s (id int NOT NULL PRIMARY KEY, name varchar(40) NOT NULL, ts timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP) ENGINE=InnoDB', $t));
                break;
            case 'compressed':
                if (!$maria) {
                    self::markTestSkipped('COMPRESSED columns are a MariaDB feature.');
                }
                $this->raw(\sprintf('CREATE TABLE %s (id int NOT NULL PRIMARY KEY, name varchar(40) NOT NULL, payload text COMPRESSED NULL) ENGINE=InnoDB', $t));
                break;
            case 'invisible_index':
                $this->onlyMySqlFamily();
                $this->raw(\sprintf('CREATE TABLE %s (id int NOT NULL PRIMARY KEY, name varchar(40) NOT NULL, qty int NULL, INDEX idx_inv (name) %s) ENGINE=InnoDB', $t, $maria ? 'IGNORED' : 'INVISIBLE'));
                break;
            case 'partitions':
                if ($pg) {
                    $this->raw(\sprintf('CREATE TABLE %s (id integer NOT NULL, d date NOT NULL, name character varying(40), PRIMARY KEY (id, d)) PARTITION BY RANGE (d)', $t));
                    $this->raw(\sprintf('CREATE TABLE %s PARTITION OF %s FOR VALUES FROM (%s) TO (%s)', $this->q($table.'_p0'), $t, "'2020-01-01'", "'2030-01-01'"));
                    $this->tables[] = $table.'_p0';
                } else {
                    $this->raw(\sprintf("CREATE TABLE %s (id int NOT NULL, d date NOT NULL, name varchar(40) NULL, PRIMARY KEY (id, d)) ENGINE=InnoDB PARTITION BY RANGE COLUMNS(d) (PARTITION p0 VALUES LESS THAN ('2025-01-01'), PARTITION pmax VALUES LESS THAN (MAXVALUE))", $t));
                }
                break;
            case 'index_options':
                $this->onlyMySqlFamily();
                $this->raw(\sprintf('CREATE TABLE %s (id int NOT NULL PRIMARY KEY, name varchar(40) NOT NULL, title varchar(60) NULL, qty int NULL, geo geometry NOT NULL, SPATIAL KEY idx_sp (geo), FULLTEXT KEY ft_title (title), KEY idx_pref (name(10)), KEY idx_desc (qty DESC)%s) ENGINE=InnoDB', $t, $maria ? '' : ', KEY idx_fn ((qty + 1))'));
                break;
            case 'check':
                $this->onlyMySqlFamily();
                $this->raw(\sprintf('CREATE TABLE %s (id int NOT NULL PRIMARY KEY, name varchar(40) NOT NULL, qty int NULL CHECK (qty > 0), CONSTRAINT chk_name CHECK (name <> %s)) ENGINE=InnoDB', $t, "''"));
                break;
            case 'versioning':
                if (!$maria) {
                    self::markTestSkipped('System versioning is a MariaDB feature.');
                }
                $this->raw(\sprintf('CREATE TABLE %s (id int NOT NULL PRIMARY KEY, name varchar(40) NOT NULL) ENGINE=InnoDB WITH SYSTEM VERSIONING', $t));
                break;
            case 'period_columns':
                if (!$maria) {
                    self::markTestSkipped('System versioning is a MariaDB feature.');
                }
                $this->raw(\sprintf('CREATE TABLE %s (id int NOT NULL PRIMARY KEY, name varchar(40) NOT NULL, row_start timestamp(6) GENERATED ALWAYS AS ROW START, row_end timestamp(6) GENERATED ALWAYS AS ROW END, PERIOD FOR SYSTEM_TIME (row_start, row_end)) ENGINE=InnoDB WITH SYSTEM VERSIONING', $t));
                break;
            default:
                throw new \LogicException('Unknown case.');
        }

        return $table;
    }

    private function onlyMySqlFamily(): void
    {
        if (Engine::PostgreSql === $this->engine) {
            self::markTestSkipped('No equivalent on PostgreSQL.');
        }
    }

    /**
     * Combien de fois la particularité apparaît dans la définition réelle de la table (le moteur, pas notre modèle).
     */
    private function mark(string $table, string $case): int
    {
        if (Engine::PostgreSql === $this->engine) {
            $sql = match ($case) {
                'generated' => \sprintf("SELECT count(*) FROM information_schema.columns WHERE table_name = '%s' AND is_generated = 'ALWAYS'", $table),
                'partitions' => \sprintf("SELECT count(*) FROM pg_partitioned_table pt JOIN pg_class c ON c.oid = pt.partrelid WHERE c.relname = '%s'", $table),
                default => throw new \LogicException('No check.'),
            };

            return (int) $this->scalar($sql);
        }

        $create = (string) ($this->rows(\sprintf('SHOW CREATE TABLE `%s`', $table))[0][1] ?? '');
        $needle = match ($case) {
            'generated' => ' AS (',
            'invisible' => 'INVISIBLE',
            'on_update' => 'ON UPDATE',
            'compressed' => 'COMPRESSED',
            'invisible_index' => Engine::MariaDb === $this->engine ? 'IGNORED' : 'INVISIBLE',
            'partitions' => 'PARTITION',
            'index_options' => 'FULLTEXT KEY',
            'check' => 'CHECK',
            'versioning' => 'WITH SYSTEM VERSIONING',
            'period_columns' => 'PERIOD FOR SYSTEM_TIME',
            default => throw new \LogicException('No check.'),
        };

        if ('index_options' === $case) {
            return substr_count($create, $needle) + substr_count($create, '(10)') + substr_count($create, ' DESC') + substr_count($create, '+ 1))') + substr_count($create, 'SPATIAL KEY');
        }

        return substr_count($create, $needle);
    }

    private function open(string $env): void
    {
        $this->engine = TargetDatabase::engine($env);
        $parameters = TargetDatabase::parameters($env);
        $connect = fn (): TargetConnection => Engine::PostgreSql === $this->engine
            ? (new PostgreSqlDriver(new DbalConnector()))->connect($this->engine, $parameters)
            : (new MySqlDriver(new DbalConnector()))->connect($this->engine, $parameters);
        try {
            $this->target = $connect();
            $this->reader = $connect();
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

    /**
     * @return list<list<bool|float|int|string|null>>
     */
    private function rows(string $sql): array
    {
        \assert(null !== $this->reader);

        return $this->reader->runner()->run($sql, StatementKind::Read, new QueryOptions())->rows;
    }

    private function scalar(string $sql): bool|float|int|string|null
    {
        return $this->rows($sql)[0][0] ?? null;
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
