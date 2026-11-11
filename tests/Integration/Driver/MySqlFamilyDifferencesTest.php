<?php

declare(strict_types=1);

namespace App\Tests\Integration\Driver;

use App\Core\Connection\Engine;
use App\Core\Driver\Exception\TargetConnectionFailedException;
use App\Core\Driver\TargetConnection;
use App\Core\Schema\Change\ColumnDraft;
use App\Core\Schema\Change\DdlStatement;
use App\Core\Schema\Change\ForeignKeyDraft;
use App\Core\Schema\Change\IndexDraft;
use App\Core\Schema\Change\SchemaChangePlanner;
use App\Core\Schema\Change\TableDraft;
use App\Core\Schema\TableDefinition;
use App\Driver\DbalConnector;
use App\Driver\MySql\MySqlDriver;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Ce que MySQL et MariaDB ne disent pas de la même façon (valeurs par défaut, JSON, séquences), rejoué contre les quatre cibles
 * sur des objets jetables : l'éditeur ne doit proposer aucun changement parasite.
 */
#[Group('integration')]
final class MySqlFamilyDifferencesTest extends TestCase
{
    private ?TargetConnection $target = null;
    private Engine $engine;

    /** @var list<string> */
    private array $drops = [];

    protected function tearDown(): void
    {
        foreach (array_reverse($this->drops) as $drop) {
            try {
                $this->raw($drop);
            } catch (\Throwable) {
            }
        }
        $this->target?->close();
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function targets(): iterable
    {
        yield 'MySQL' => ['TARGET_MYSQL_URL'];
        yield 'MySQL 26' => ['TARGET_MYSQL26_URL'];
        yield 'MariaDB' => ['TARGET_MARIADB_URL'];
        yield 'MariaDB 13' => ['TARGET_MARIADB13_URL'];
    }

    private function open(string $env): void
    {
        $this->engine = TargetDatabase::engine($env);
        try {
            $this->target = (new MySqlDriver(new DbalConnector()))->connect($this->engine, TargetDatabase::parameters($env));
        } catch (TargetConnectionFailedException) {
            self::markTestSkipped('The target is unreachable (docker compose --profile targets up -d --wait).');
        }
    }

    private function raw(string $sql): void
    {
        \assert(null !== $this->target);
        $this->target->ddl()->execute([new DdlStatement($sql, 'raw', 'raw', false)], false);
    }

    private function table(string $columns): string
    {
        $name = 'tdb_diff_'.bin2hex(random_bytes(4));
        $this->drops[] = 'DROP TABLE IF EXISTS `'.$name.'`';
        $this->raw('CREATE TABLE `'.$name.'` ('.$columns.') ENGINE=InnoDB');

        return $name;
    }

    private function describe(string $name): TableDefinition
    {
        \assert(null !== $this->target);

        return $this->target->introspector()->describeTable($name);
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

    private const string DEFAULTS = "id int NOT NULL PRIMARY KEY, created timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP, created6 datetime(6) NULL DEFAULT CURRENT_TIMESTAMP(6),
        label varchar(20) NOT NULL DEFAULT 'abc', quoted varchar(20) NULL DEFAULT 'it''s', n int NOT NULL DEFAULT 5, price decimal(6,2) NOT NULL DEFAULT 1.50,
        calc int NOT NULL DEFAULT (1 + 1), token varchar(36) NOT NULL DEFAULT (uuid()), maybe int NULL DEFAULT NULL, plain int NULL, y year NULL, flag tinyint(1) NOT NULL DEFAULT 0";

    #[DataProvider('targets')]
    public function testReadingThenPreviewingTheSameDraftChangesNothing(string $env): void
    {
        $this->open($env);
        $name = $this->table(self::DEFAULTS);
        $table = $this->describe($name);

        $plan = (new SchemaChangePlanner())->plan($this->engine, $table, self::draftOf($table));

        self::assertSame([], $plan->statements, var_export(array_map(static fn ($c) => [$c->name, $c->type, $c->default], $table->columns), true));
    }

    #[DataProvider('targets')]
    public function testChangingOneColumnKeepsTheDefaultsOfTheOthers(string $env): void
    {
        $this->open($env);
        $name = $this->table(self::DEFAULTS);
        $before = $this->describe($name);

        // Les colonnes à défaut dynamique changent de nullabilité : leur définition est redite en entier.
        $columns = array_map(
            static fn ($c): ColumnDraft => new ColumnDraft($c->name, $c->name, $c->type, \in_array($c->name, ['created', 'calc', 'token'], true) ? !$c->nullable : $c->nullable, $c->default, $c->autoIncrement),
            $before->columns,
        );
        $draft = new TableDraft($name, $columns, self::draftOf($before)->indexes, []);
        $plan = (new SchemaChangePlanner())->plan($this->engine, $before, $draft);
        \assert(null !== $this->target);
        $this->target->ddl()->execute($plan->statements, $plan->transactional);
        $after = $this->describe($name);

        // Les valeurs par défaut se lisent comme avant, et restent des expressions, pas du texte.
        foreach (['created', 'created6', 'label', 'quoted', 'n', 'price', 'calc', 'token', 'flag'] as $column) {
            $b = $before->column($column);
            $a = $after->column($column);
            self::assertNotNull($a);
            self::assertNotNull($b);
            self::assertSame($b->default, $a->default, $column);
        }
    }

    #[DataProvider('targets')]
    public function testASequenceIsNotAnEditableTable(string $env): void
    {
        $this->open($env);
        if (Engine::MariaDb !== $this->engine) {
            self::markTestSkipped('Sequences are a MariaDB object.');
        }
        $name = 'tdb_seq_'.bin2hex(random_bytes(4));
        $this->drops[] = 'DROP SEQUENCE IF EXISTS `'.$name.'`';
        $this->raw('CREATE SEQUENCE `'.$name.'`');
        \assert(null !== $this->target);

        $found = null;
        foreach ($this->target->introspector()->listTables() as $summary) {
            if ($summary->name === $name) {
                $found = $summary;
            }
        }
        self::assertNotNull($found);
        self::assertSame('sequence', $found->kind->value);
        self::assertSame('sequence', $this->describe($name)->kind->value);
    }

    #[DataProvider('targets')]
    public function testAJsonColumnKeepsItsNativeNameOnBothFamilies(string $env): void
    {
        $this->open($env);
        $name = $this->table('id int NOT NULL PRIMARY KEY, doc json NULL');
        $type = $this->describe($name)->column('doc')?->type;

        self::assertSame(Engine::MariaDb === $this->engine ? 'longtext' : 'json', $type);
    }
}
