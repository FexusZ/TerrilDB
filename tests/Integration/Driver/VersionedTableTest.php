<?php

declare(strict_types=1);

namespace App\Tests\Integration\Driver;

use App\Core\Driver\Exception\TargetConnectionFailedException;
use App\Core\Driver\TargetConnection;
use App\Core\Data\DataChangeService;
use App\Core\Data\DataDraft;
use App\Core\Data\Exception\InvalidDataChangeException;
use App\Core\Data\RowInsert;
use App\Core\Data\RowUpdate;
use App\Core\Guard\StatementKind;
use App\Core\Query\QueryOptions;
use App\Core\Schema\Change\ColumnDraft;
use App\Core\Schema\Change\DdlStatement;
use App\Core\Schema\Change\Exception\SchemaChangeNeedsConfirmationException;
use App\Core\Schema\Change\Exception\UnsupportedSchemaChangeException;
use App\Core\Schema\Change\IndexDraft;
use App\Core\Schema\Change\PlanLoss;
use App\Core\Schema\Change\SchemaChangePlanner;
use App\Core\Schema\Change\SchemaChangeService;
use App\Core\Schema\Change\TableDraft;
use App\Core\Schema\TableDefinition;
use App\Core\Schema\LockReason;
use App\Driver\DbalConnector;
use App\Driver\MySql\MySqlDriver;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Tables à versionnement système (MariaDB, WITH SYSTEM VERSIONING) : l'introspecteur les reconnaît et verrouille leurs colonnes
 * de période ; les autres moteurs n'ont pas cette notion. Tables jetables.
 */
#[Group('integration')]
final class VersionedTableTest extends TestCase
{
    private ?TargetConnection $target = null;
    /** Connexion à part pour relire : le runner laisse la session en lecture seule. */
    private ?TargetConnection $reader = null;

    /** @var list<string> */
    private array $tables = [];

    protected function tearDown(): void
    {
        foreach ($this->tables as $table) {
            try {
                $this->raw(\sprintf('DROP TABLE IF EXISTS `%s`', $table));
            } catch (\Throwable) {
            }
        }
        $this->target?->close();
        $this->reader?->close();
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function mariaDb(): iterable
    {
        yield 'MariaDB' => ['TARGET_MARIADB_URL'];
        yield 'MariaDB 13' => ['TARGET_MARIADB13_URL'];
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function mySql(): iterable
    {
        yield 'MySQL' => ['TARGET_MYSQL_URL'];
        yield 'MySQL 26' => ['TARGET_MYSQL26_URL'];
    }

    #[DataProvider('mariaDb')]
    public function testATableWithImplicitPeriodColumnsIsVersionedAndShowsNoHiddenColumn(string $env): void
    {
        $table = $this->create($env, 'CREATE TABLE `%s` (id int NOT NULL PRIMARY KEY, name varchar(40) NULL) ENGINE=InnoDB WITH SYSTEM VERSIONING');
        \assert(null !== $this->target);

        $definition = $this->target->introspector()->describeTable($table);

        self::assertTrue($definition->versioned);
        self::assertSame(['id', 'name'], array_map(static fn ($c): string => $c->name, $definition->columns));
        self::assertSame(['id'], $definition->primaryKey()?->columns);
    }

    #[DataProvider('mariaDb')]
    public function testExplicitPeriodColumnsAreLockedAsSystemPeriod(string $env): void
    {
        $table = $this->create($env, 'CREATE TABLE `%s` (id int NOT NULL PRIMARY KEY, name varchar(40) NULL, rs timestamp(6) GENERATED ALWAYS AS ROW START, re timestamp(6) GENERATED ALWAYS AS ROW END, PERIOD FOR SYSTEM_TIME (rs, re)) ENGINE=InnoDB WITH SYSTEM VERSIONING');
        \assert(null !== $this->target);

        $definition = $this->target->introspector()->describeTable($table);

        self::assertTrue($definition->versioned);
        self::assertSame(LockReason::SystemPeriod, $definition->column('rs')?->lock);
        self::assertSame(LockReason::SystemPeriod, $definition->column('re')?->lock);
        self::assertNull($definition->column('name')?->lock);
    }

    #[DataProvider('mariaDb')]
    public function testAnOrdinaryTableIsNotVersioned(string $env): void
    {
        $table = $this->create($env, 'CREATE TABLE `%s` (id int NOT NULL PRIMARY KEY, name varchar(40) NULL) ENGINE=InnoDB');
        \assert(null !== $this->target);

        self::assertFalse($this->target->introspector()->describeTable($table)->versioned);
    }

    #[DataProvider('mySql')]
    public function testMySqlHasNoSuchNotion(string $env): void
    {
        $table = $this->create($env, 'CREATE TABLE `%s` (id int NOT NULL PRIMARY KEY, name varchar(40) NULL) ENGINE=InnoDB');
        \assert(null !== $this->target);

        self::assertFalse($this->target->introspector()->describeTable($table)->versioned);
    }

    #[DataProvider('mariaDb')]
    public function testAnUnchangedDraftOfAVersionedTableProducesNoSql(string $env): void
    {
        foreach (['CREATE TABLE `%s` (id int NOT NULL PRIMARY KEY, name varchar(40) NULL) ENGINE=InnoDB WITH SYSTEM VERSIONING', 'CREATE TABLE `%s` (id int NOT NULL PRIMARY KEY, name varchar(40) NULL, rs timestamp(6) GENERATED ALWAYS AS ROW START, re timestamp(6) GENERATED ALWAYS AS ROW END, PERIOD FOR SYSTEM_TIME (rs, re)) ENGINE=InnoDB WITH SYSTEM VERSIONING'] as $sql) {
            $table = $this->create($env, $sql);
            \assert(null !== $this->target);
            $current = $this->target->introspector()->describeTable($table);

            $preview = (new SchemaChangeService())->preview($this->target, $table, self::draftOf($current), SchemaChangePlanner::fingerprint($current));

            self::assertSame([], $preview->plan->statements);
        }
    }

    #[DataProvider('mariaDb')]
    public function testDroppingThePeriodColumnsIsRefusedAndNothingIsSent(string $env): void
    {
        $table = $this->create($env, 'CREATE TABLE `%s` (id int NOT NULL PRIMARY KEY, name varchar(40) NULL, rs timestamp(6) GENERATED ALWAYS AS ROW START, re timestamp(6) GENERATED ALWAYS AS ROW END, PERIOD FOR SYSTEM_TIME (rs, re)) ENGINE=InnoDB WITH SYSTEM VERSIONING');
        \assert(null !== $this->target);
        $current = $this->target->introspector()->describeTable($table);
        $draft = self::draftOf($current);
        $without = new TableDraft($draft->name, array_values(array_filter($draft->columns, static fn (ColumnDraft $c): bool => !\in_array($c->name, ['rs', 're'], true))), array_map(static fn (IndexDraft $i): IndexDraft => $i->primary ? new IndexDraft($i->originalName, $i->name, ['id'], true, true, null, null) : $i, $draft->indexes), []);

        try {
            (new SchemaChangeService())->apply($this->target, $table, $without, SchemaChangePlanner::fingerprint($current), true);
            self::fail('Dropping the period columns should be refused.');
        } catch (UnsupportedSchemaChangeException $e) {
            self::assertSame(LockReason::SystemPeriod, $e->reason);
        }
        $create = (string) ($this->rows(\sprintf('SHOW CREATE TABLE `%s`', $table))[0][1] ?? '');
        self::assertStringContainsString('WITH SYSTEM VERSIONING', $create);
        self::assertStringContainsString('PERIOD FOR SYSTEM_TIME', $create);
    }

    #[DataProvider('mariaDb')]
    public function testDroppingAVersionedTableNeedsAConfirmationThatNamesTheHistory(string $env): void
    {
        $table = $this->create($env, 'CREATE TABLE `%s` (id int NOT NULL PRIMARY KEY, name varchar(40) NULL) ENGINE=InnoDB WITH SYSTEM VERSIONING');
        \assert(null !== $this->target);
        $current = $this->target->introspector()->describeTable($table);
        $service = new SchemaChangeService();

        $preview = $service->preview($this->target, $table, null, SchemaChangePlanner::fingerprint($current));
        self::assertEquals([new PlanLoss('system_history', $table)], $preview->plan->losses);

        try {
            $service->apply($this->target, $table, null, SchemaChangePlanner::fingerprint($current), false);
            self::fail('Dropping a versioned table needs a confirmation.');
        } catch (SchemaChangeNeedsConfirmationException $e) {
            self::assertEquals([new PlanLoss('system_history', $table)], $e->plan->losses);
        }
        self::assertSame(1, (int) $this->rows(\sprintf("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '%s'", $table))[0][0]);

        $service->apply($this->target, $table, null, SchemaChangePlanner::fingerprint($current), true);
        self::assertSame(0, (int) $this->rows(\sprintf("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '%s'", $table))[0][0]);
    }

    #[DataProvider('mariaDb')]
    public function testTheDataGridWritesNeverNameTheHiddenPeriodColumnsAndKeepTheHistory(string $env): void
    {
        $table = $this->create($env, 'CREATE TABLE `%s` (id int NOT NULL PRIMARY KEY, name varchar(40) NULL) ENGINE=InnoDB WITH SYSTEM VERSIONING');
        \assert(null !== $this->target);
        $service = new DataChangeService();

        $service->apply($this->target, $table, new DataDraft([], [new RowInsert(['id' => 1, 'name' => 'a'])], []), false);
        $plan = $service->apply($this->target, $table, new DataDraft([new RowUpdate(['id' => 1, 'name' => 'a'], ['name' => 'b'])], [], []), false);

        self::assertStringNotContainsStringIgnoringCase('row_', $plan->statements[0]->sql);
        self::assertSame(2, (int) $this->rows(\sprintf('SELECT COUNT(*) FROM `%s` FOR SYSTEM_TIME ALL', $table))[0][0]);
        self::assertSame('b', $this->rows(\sprintf('SELECT name FROM `%s`', $table))[0][0]);
    }

    #[DataProvider('mariaDb')]
    public function testAValueGivenToAnExplicitPeriodColumnIsRefusedBeforeBeingSent(string $env): void
    {
        $table = $this->create($env, 'CREATE TABLE `%s` (id int NOT NULL PRIMARY KEY, name varchar(40) NULL, rs timestamp(6) GENERATED ALWAYS AS ROW START, re timestamp(6) GENERATED ALWAYS AS ROW END, PERIOD FOR SYSTEM_TIME (rs, re)) ENGINE=InnoDB WITH SYSTEM VERSIONING');
        \assert(null !== $this->target);

        $this->expectException(InvalidDataChangeException::class);
        (new DataChangeService())->preview($this->target, $table, new DataDraft([], [new RowInsert(['id' => 1, 'rs' => '2020-01-01 00:00:00'])], []));
    }

    private static function draftOf(TableDefinition $table): TableDraft
    {
        return new TableDraft(
            $table->name,
            array_map(static fn ($c): ColumnDraft => new ColumnDraft($c->name, $c->name, $c->type, $c->nullable, $c->default, $c->autoIncrement), $table->columns),
            array_map(static fn ($i): IndexDraft => new IndexDraft($i->name, $i->name, $i->columns, $i->unique, $i->primary, null, null), $table->indexes),
            [],
        );
    }

    /**
     * @return list<list<bool|float|int|string|null>>
     */
    private function rows(string $sql): array
    {
        \assert(null !== $this->reader);

        return $this->reader->runner()->run($sql, StatementKind::Read, new QueryOptions())->rows;
    }

    private function create(string $env, string $sql): string
    {
        $engine = TargetDatabase::engine($env);
        try {
            $this->target ??= (new MySqlDriver(new DbalConnector()))->connect($engine, TargetDatabase::parameters($env));
        } catch (TargetConnectionFailedException) {
            self::markTestSkipped('The target is unreachable (docker compose --profile targets up -d --wait).');
        }
        $this->reader ??= (new MySqlDriver(new DbalConnector()))->connect($engine, TargetDatabase::parameters($env));
        $table = $this->tables[] = 'tdb_ver_'.bin2hex(random_bytes(4));
        $this->raw(\sprintf($sql, $table));

        return $table;
    }

    private function raw(string $sql): void
    {
        \assert(null !== $this->target);
        $this->target->ddl()->execute([new DdlStatement($sql, 'raw', 'raw', false)], false);
    }
}
