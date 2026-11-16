<?php

declare(strict_types=1);

namespace App\Tests\Core\Schema\Change\Algorithm;

use App\Core\Connection\Engine;
use App\Core\Driver\ServerVersion;
use App\Core\Schema\Change\Algorithm\AlgorithmAdvice;
use App\Core\Schema\Change\Algorithm\AlterAlgorithm;
use App\Core\Schema\Change\Algorithm\AlterAlgorithmAdvisor;
use App\Core\Schema\Change\ColumnDraft;
use App\Core\Schema\Change\ForeignKeyDraft;
use App\Core\Schema\Change\IndexDraft;
use App\Core\Schema\Change\SchemaChangePlanner;
use App\Core\Schema\Change\TableDraft;
use App\Core\Schema\ColumnDefinition;
use App\Core\Schema\ForeignKeyDefinition;
use App\Core\Schema\IndexDefinition;
use App\Core\Schema\ReferentialAction;
use App\Core\Schema\TableDefinition;
use App\Core\Schema\TableKind;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * La table de règles d'algorithme, jugée sur le plan que le vrai planificateur écrit. Chaque verdict affirmé ici est vérifié
 * contre les vrais moteurs par tests/Integration/Driver/AlterAlgorithmTest ; ce qui n'est pas vérifié doit rester « unknown ».
 */
final class AlterAlgorithmAdvisorTest extends TestCase
{
    private const MYSQL = [Engine::MySql, '8.4.13'];
    private const MYSQL26 = [Engine::MySql, '26.10.0'];
    private const MARIADB = [Engine::MariaDb, '11.8.9-MariaDB-ubu2404'];
    private const MARIADB13 = [Engine::MariaDb, '13.0.2-MariaDB-ubu2604'];

    /**
     * @param list<ColumnDefinition> $columns
     */
    private static function table(string $engine = 'InnoDB', ?array $columns = null, ?int $rows = 120_000): TableDefinition
    {
        return new TableDefinition(
            'orders',
            TableKind::Table,
            $columns ?? [
                new ColumnDefinition('id', 'int', false, null, true, null),
                new ColumnDefinition('a', 'varchar(50)', false, null, false, null),
                new ColumnDefinition('b', 'int', true, null, false, null),
                new ColumnDefinition('c', 'varchar(20)', true, null, false, null),
                new ColumnDefinition('d', 'int', true, '1', false, null),
                new ColumnDefinition('n', 'int', true, null, false, null),
                new ColumnDefinition('en', "enum('a','b')", true, null, false, null),
            ],
            [
                new IndexDefinition('PRIMARY', ['id'], true, true),
                new IndexDefinition('k_b', ['b'], false, false),
                new IndexDefinition('k_d', ['d'], false, false),
            ],
            [new ForeignKeyDefinition('fk0', ['d'], 'parent', ['id'], ReferentialAction::Restrict, ReferentialAction::Restrict)],
            null,
            $rows,
            4_000_000,
            1_000_000,
            $engine,
            true,
        );
    }

    private static function draftOf(TableDefinition $table): TableDraft
    {
        return new TableDraft(
            $table->name,
            array_map(static fn (ColumnDefinition $c): ColumnDraft => new ColumnDraft($c->name, $c->name, $c->type, $c->nullable, $c->default, $c->autoIncrement), $table->columns),
            array_map(static fn (IndexDefinition $i): IndexDraft => new IndexDraft($i->name, $i->name, $i->columns, $i->unique, $i->primary, null, null), $table->indexes),
            array_map(static fn (ForeignKeyDefinition $f): ForeignKeyDraft => new ForeignKeyDraft($f->name, $f->name, $f->columns, $f->referencedTable, $f->referencedColumns, $f->onUpdate, $f->onDelete), $table->foreignKeys),
        );
    }

    /**
     * @param callable(TableDraft): TableDraft $change
     * @param array{Engine, string}            $target
     *
     * @return list<AlgorithmAdvice>
     */
    private static function advise(array $target, callable $change, ?TableDefinition $current = null): array
    {
        [$engine, $version] = $target;
        $current ??= self::table();
        $draft = $change(self::draftOf($current));
        $plan = (new SchemaChangePlanner())->plan($engine, $current, $draft);

        return (new AlterAlgorithmAdvisor())->advise($engine, ServerVersion::parse($version), $current, $draft, $plan);
    }

    /**
     * @param array<ColumnDraft>|null     $columns
     * @param array<IndexDraft>|null      $indexes
     * @param array<ForeignKeyDraft>|null $foreignKeys
     */
    private static function with(TableDraft $draft, ?array $columns = null, ?array $indexes = null, ?array $foreignKeys = null, ?string $name = null): TableDraft
    {
        return new TableDraft($name ?? $draft->name, array_values($columns ?? $draft->columns), array_values($indexes ?? $draft->indexes), array_values($foreignKeys ?? $draft->foreignKeys));
    }

    private static function replaceColumn(TableDraft $draft, string $name, string $type, ?bool $nullable = null, ?string $default = null, bool $keepDefault = true, ?string $newName = null): TableDraft
    {
        $columns = array_map(static function (ColumnDraft $c) use ($name, $type, $nullable, $default, $keepDefault, $newName): ColumnDraft {
            if ($c->name !== $name) {
                return $c;
            }

            return new ColumnDraft($c->originalName, $newName ?? $c->name, $type, $nullable ?? $c->nullable, $keepDefault ? $c->default : $default, $c->autoIncrement);
        }, $draft->columns);

        return self::with(
            $draft,
            columns: $columns,
            indexes: array_map(static fn (IndexDraft $i): IndexDraft => new IndexDraft($i->originalName, $i->name, self::follow($i->columns, $name, $newName), $i->unique, $i->primary, null, null), $draft->indexes),
            foreignKeys: array_map(static fn (ForeignKeyDraft $f): ForeignKeyDraft => new ForeignKeyDraft($f->originalName, $f->name, self::follow($f->columns, $name, $newName), $f->referencedTable, $f->referencedColumns, $f->onUpdate, $f->onDelete), $draft->foreignKeys),
        );
    }

    /**
     * @param list<string> $names
     *
     * @return list<string>
     */
    private static function follow(array $names, string $from, ?string $to): array
    {
        return array_map(static fn (string $n): string => $n === $from ? ($to ?? $n) : $n, $names);
    }

    private static function addColumn(TableDraft $draft, string $type = 'int', bool $nullable = true, ?string $default = null, bool $auto = false): TableDraft
    {
        return self::with($draft, columns: [...$draft->columns, new ColumnDraft(null, 'x', $type, $nullable, $default, $auto)]);
    }

    /**
     * @return iterable<string, array{array{Engine, string}}>
     */
    public static function mySqlFamily(): iterable
    {
        yield 'MySQL 8.4' => [self::MYSQL];
        yield 'MySQL 26' => [self::MYSQL26];
        yield 'MariaDB 11.8' => [self::MARIADB];
        yield 'MariaDB 13' => [self::MARIADB13];
    }

    /**
     * @param array{Engine, string} $target
     */
    #[DataProvider('mySqlFamily')]
    public function testAddingAColumnAtTheEndIsInstantEvenOnABigTable(array $target): void
    {
        $advice = self::advise($target, static fn (TableDraft $d) => self::addColumn($d))[0];

        self::assertSame(AlterAlgorithm::Instant, $advice->algorithm);
        self::assertFalse($advice->blocksWrites);
        self::assertFalse($advice->copiesTable);
        self::assertNull($advice->estimatedExtraBytes);
        self::assertNull($advice->reason);
    }

    /**
     * @param array{Engine, string} $target
     */
    #[DataProvider('mySqlFamily')]
    public function testAddingColumnsWithSimpleDefaultsOrTypesIsInstant(array $target): void
    {
        foreach ([['int', false, '0'], ['varchar(10)', true, "'a'"], ['int', false, null], ['timestamp', true, 'now()'], ['text', true, null], ['json', true, null], ['datetime', true, null], ['blob', true, null]] as [$type, $nullable, $default]) {
            $advice = self::advise($target, static fn (TableDraft $d) => self::addColumn($d, $type, $nullable, $default))[0];
            self::assertSame(AlterAlgorithm::Instant, $advice->algorithm, $type.' '.($default ?? ''));
        }
    }

    /**
     * @param array{Engine, string} $target
     */
    #[DataProvider('mySqlFamily')]
    public function testAColumnWithAnExpressionDefaultOrAnAutoIncrementIsNotClaimed(array $target): void
    {
        self::assertSame(AlterAlgorithm::Unknown, self::advise($target, static fn (TableDraft $d) => self::addColumn($d, 'varchar(36)', true, 'uuid()'))[0]->algorithm);
        self::assertSame(AlterAlgorithm::Unknown, self::advise($target, static fn (TableDraft $d) => self::addColumn($d, 'int', false, null, true))[0]->algorithm);
        self::assertSame(AlterAlgorithm::Unknown, self::advise($target, static fn (TableDraft $d) => self::addColumn($d, 'geometry', true))[0]->algorithm);
    }

    /**
     * @param array{Engine, string} $target
     */
    #[DataProvider('mySqlFamily')]
    public function testDroppingAnUnindexedColumnIsInstant(array $target): void
    {
        $advice = self::advise($target, static fn (TableDraft $d) => self::with($d, columns: array_values(array_filter($d->columns, static fn (ColumnDraft $c): bool => 'c' !== $c->name))))[0];

        self::assertSame(AlterAlgorithm::Instant, $advice->algorithm);
    }

    private static function withIndexOnN(): TableDefinition
    {
        $t = self::table();

        return new TableDefinition($t->name, $t->kind, $t->columns, [...$t->indexes, new IndexDefinition('k_n', ['n'], false, false)], $t->foreignKeys, null, $t->estimatedRows, $t->dataBytes, $t->indexBytes, $t->storageEngine, true);
    }

    /**
     * @param array{Engine, string} $target
     */
    #[DataProvider('mySqlFamily')]
    public function testDroppingAColumnWhoseIndexIsDroppedInTheSamePlanIsInstant(array $target): void
    {
        $advice = self::advise($target, static fn (TableDraft $d) => self::with(
            $d,
            columns: array_values(array_filter($d->columns, static fn (ColumnDraft $c): bool => 'n' !== $c->name)),
            indexes: array_values(array_filter($d->indexes, static fn (IndexDraft $i): bool => 'k_n' !== $i->name)),
        ), self::withIndexOnN());

        self::assertSame(AlterAlgorithm::Instant, $advice[1]->algorithm, 'the index is dropped first, then the column');
    }

    public function testRenamingAColumnIsInstantOnMariaDbAndOnMySqlWhenNoIndexCouldBeReferenced(): void
    {
        $rename = static fn (TableDraft $d) => self::replaceColumn($d, 'c', 'varchar(20)', newName: 'c2');
        foreach ([self::MARIADB, self::MARIADB13, self::MYSQL, self::MYSQL26] as $target) {
            self::assertSame(AlterAlgorithm::Instant, self::advise($target, $rename)[0]->algorithm);
        }
        $renameIndexed = static fn (TableDraft $d) => self::replaceColumn($d, 'b', 'int', newName: 'b2');
        foreach ([self::MARIADB, self::MARIADB13] as $target) {
            self::assertSame(AlterAlgorithm::Instant, self::advise($target, $renameIndexed)[0]->algorithm);
        }
        foreach ([self::MYSQL, self::MYSQL26] as $target) {
            $advice = self::advise($target, $renameIndexed)[0];
            self::assertSame(AlterAlgorithm::Unknown, $advice->algorithm, 'another table could reference an indexed column: MySQL then refuses INSTANT');
        }
    }

    /**
     * @param array{Engine, string} $target
     */
    #[DataProvider('mySqlFamily')]
    public function testChangingTheTypeFamilyCopiesTheTable(array $target): void
    {
        foreach ([['n', 'bigint'], ['n', 'varchar(20)'], ['n', 'int unsigned'], ['c', 'text'], ['a', 'varchar(10)']] as [$column, $type]) {
            $advice = self::advise($target, static fn (TableDraft $d) => self::replaceColumn($d, $column, $type))[0];
            self::assertSame(AlterAlgorithm::Copy, $advice->algorithm, $column.' '.$type);
            self::assertTrue($advice->blocksWrites);
            self::assertTrue($advice->copiesTable);
            self::assertSame(5_000_000, $advice->estimatedExtraBytes, 'data + indexes');
        }
    }

    /**
     * @param array{Engine, string} $target
     */
    #[DataProvider('mySqlFamily')]
    public function testGrowingAVarcharIsNotClaimed(array $target): void
    {
        $advice = self::advise($target, static fn (TableDraft $d) => self::replaceColumn($d, 'c', 'varchar(30)'))[0];

        self::assertSame(AlterAlgorithm::Unknown, $advice->algorithm, 'the answer depends on the length-byte class (charset), not read here');
        self::assertNull($advice->blocksWrites);
        self::assertNull($advice->copiesTable);
        self::assertSame('unverified_change', $advice->reason);
    }

    /**
     * @param array{Engine, string} $target
     */
    #[DataProvider('mySqlFamily')]
    public function testAppendingEnumMembersIsInstantButReorderingCopies(array $target): void
    {
        self::assertSame(AlterAlgorithm::Instant, self::advise($target, static fn (TableDraft $d) => self::replaceColumn($d, 'en', "enum('a','b','c')"))[0]->algorithm);
        self::assertSame(AlterAlgorithm::Copy, self::advise($target, static fn (TableDraft $d) => self::replaceColumn($d, 'en', "enum('z','a','b')"))[0]->algorithm);
    }

    /**
     * @param array{Engine, string} $target
     */
    #[DataProvider('mySqlFamily')]
    public function testChangingOnlyTheDefaultIsInstant(array $target): void
    {
        $advice = self::advise($target, static fn (TableDraft $d) => self::replaceColumn($d, 'n', 'int', default: '5', keepDefault: false))[0];

        self::assertSame(AlterAlgorithm::Instant, $advice->algorithm);
    }

    /**
     * @param array{Engine, string} $target
     */
    #[DataProvider('mySqlFamily')]
    public function testDroppingNotNullIsInPlaceWithoutBlockingWrites(array $target): void
    {
        $advice = self::advise($target, static fn (TableDraft $d) => self::replaceColumn($d, 'a', 'varchar(50)', nullable: true))[0];

        self::assertSame(AlterAlgorithm::Inplace, $advice->algorithm);
        self::assertFalse($advice->blocksWrites);
    }

    public function testSettingNotNullIsInPlaceOnMariaDbAndDependsOnSqlModeOnMySql(): void
    {
        $setNotNull = static fn (TableDraft $d) => self::replaceColumn($d, 'c', 'varchar(20)', nullable: false);
        foreach ([self::MARIADB, self::MARIADB13] as $target) {
            $advice = self::advise($target, $setNotNull)[0];
            self::assertSame(AlterAlgorithm::Inplace, $advice->algorithm);
            self::assertFalse($advice->blocksWrites);
        }
        foreach ([self::MYSQL, self::MYSQL26] as $target) {
            $advice = self::advise($target, $setNotNull)[0];
            self::assertSame(AlterAlgorithm::Unknown, $advice->algorithm);
            self::assertSame('depends_on_sql_mode', $advice->reason);
        }
    }

    /**
     * @param array{Engine, string} $target
     */
    #[DataProvider('mySqlFamily')]
    public function testSeveralChangesOnOneColumnTakeTheWorstVerdict(array $target): void
    {
        $advice = self::advise($target, static fn (TableDraft $d) => self::replaceColumn($d, 'n', 'int', nullable: false, default: '4', keepDefault: false))[0];

        self::assertSame(Engine::MariaDb === $target[0] ? AlterAlgorithm::Inplace : AlterAlgorithm::Unknown, $advice->algorithm, 'NOT NULL in place needs a strict sql_mode on MySQL');
        $withType = self::advise($target, static fn (TableDraft $d) => self::replaceColumn($d, 'n', 'bigint', nullable: false, default: '4', keepDefault: false))[0];
        self::assertSame(AlterAlgorithm::Copy, $withType->algorithm);
    }

    /**
     * @param array{Engine, string} $target
     */
    #[DataProvider('mySqlFamily')]
    public function testIndexesAreBuiltInPlaceWithoutBlockingWrites(array $target): void
    {
        $add = self::advise($target, static fn (TableDraft $d) => self::with($d, indexes: [...$d->indexes, new IndexDraft(null, 'k_c', ['c'], false, false, null, null)]))[0];
        self::assertSame(AlterAlgorithm::Inplace, $add->algorithm);
        self::assertFalse($add->blocksWrites);
        $unique = self::advise($target, static fn (TableDraft $d) => self::with($d, indexes: [...$d->indexes, new IndexDraft(null, 'u_c', ['c'], true, false, null, null)]))[0];
        self::assertSame(AlterAlgorithm::Inplace, $unique->algorithm);
        $drop = self::advise($target, static fn (TableDraft $d) => self::with($d, indexes: array_values(array_filter($d->indexes, static fn (IndexDraft $i): bool => 'k_b' !== $i->name))))[0];
        self::assertSame(AlterAlgorithm::Inplace, $drop->algorithm);
        self::assertFalse($drop->blocksWrites);
    }

    public function testRenamingAnIndexOrDroppingAForeignKeyIsInstantOnMariaDbOnlyOtherwiseInPlace(): void
    {
        $renameIndex = static fn (TableDraft $d) => self::with($d, indexes: array_map(static fn (IndexDraft $i): IndexDraft => 'k_b' === $i->name ? new IndexDraft('k_b', 'k_b2', $i->columns, false, false, null, null) : $i, $d->indexes));
        $dropForeignKey = static fn (TableDraft $d) => self::with($d, foreignKeys: []);
        foreach ([self::MARIADB, self::MARIADB13] as $target) {
            self::assertSame(AlterAlgorithm::Instant, self::advise($target, $renameIndex)[0]->algorithm);
            self::assertSame(AlterAlgorithm::Instant, self::advise($target, $dropForeignKey)[0]->algorithm);
        }
        foreach ([self::MYSQL, self::MYSQL26] as $target) {
            self::assertSame(AlterAlgorithm::Inplace, self::advise($target, $renameIndex)[0]->algorithm);
            self::assertSame(AlterAlgorithm::Inplace, self::advise($target, $dropForeignKey)[0]->algorithm);
            self::assertFalse(self::advise($target, $dropForeignKey)[0]->blocksWrites);
        }
    }

    /**
     * @param array{Engine, string} $target
     */
    #[DataProvider('mySqlFamily')]
    public function testAddingAForeignKeyCopiesTheTable(array $target): void
    {
        $advice = self::advise($target, static fn (TableDraft $d) => self::with($d, foreignKeys: [...$d->foreignKeys, new ForeignKeyDraft(null, 'fk_n', ['n'], 'parent', ['id'], ReferentialAction::Restrict, ReferentialAction::Restrict)]))[0];

        self::assertSame(AlterAlgorithm::Copy, $advice->algorithm);
        self::assertTrue($advice->blocksWrites);
        self::assertSame(5_000_000, $advice->estimatedExtraBytes);
    }

    /**
     * @param array{Engine, string} $target
     */
    #[DataProvider('mySqlFamily')]
    public function testPrimaryKeyChangesAreJudgedStatementByStatement(array $target): void
    {
        $noKey = new TableDefinition('orders', TableKind::Table, [new ColumnDefinition('id', 'int', false, null, false, null), new ColumnDefinition('x', 'int', false, null, false, null)], [], [], null, 10, 100, 0, 'InnoDB', true);
        $add = self::advise($target, static fn (TableDraft $d) => self::with($d, indexes: [new IndexDraft(null, 'PRIMARY', ['id'], true, true, null, null)]), $noKey)[0];
        self::assertSame(AlterAlgorithm::Inplace, $add->algorithm);
        self::assertFalse($add->blocksWrites);

        $keyed = new TableDefinition('orders', TableKind::Table, [new ColumnDefinition('id', 'int', false, null, false, null), new ColumnDefinition('x', 'int', false, null, false, null)], [new IndexDefinition('PRIMARY', ['id'], true, true)], [], null, 10, 100, 0, 'InnoDB', true);
        $drop = self::advise($target, static fn (TableDraft $d) => self::with($d, indexes: []), $keyed)[0];
        self::assertSame(AlterAlgorithm::Copy, $drop->algorithm);
        self::assertTrue($drop->blocksWrites);
    }

    /**
     * @param array{Engine, string} $target
     */
    #[DataProvider('mySqlFamily')]
    public function testRenamingTheTableIsInstant(array $target): void
    {
        $advice = self::advise($target, static fn (TableDraft $d) => self::with($d, name: 'orders2'))[0];

        self::assertSame(AlterAlgorithm::Instant, $advice->algorithm);
    }

    public function testDroppingATableIsNotAnAlter(): void
    {
        $current = self::table();
        $plan = (new SchemaChangePlanner())->planDrop(Engine::MySql, $current);
        $advice = (new AlterAlgorithmAdvisor())->advise(Engine::MySql, ServerVersion::parse('8.4.13'), $current, null, $plan)[0];

        self::assertSame(AlterAlgorithm::Unknown, $advice->algorithm);
        self::assertSame('not_an_alter', $advice->reason);
    }

    public function testAnUnverifiedVersionOrAnUnknownVersionIsNeverClaimed(): void
    {
        foreach ([[Engine::MySql, '8.0.40'], [Engine::MySql, '9.1.0'], [Engine::MariaDb, '10.11.6-MariaDB'], [Engine::MariaDb, '12.0.1-MariaDB'], [Engine::MySql, '8.0.30-Vitess']] as $target) {
            $advice = self::advise($target, static fn (TableDraft $d) => self::addColumn($d))[0];
            self::assertSame(AlterAlgorithm::Unknown, $advice->algorithm, $target[1]);
            self::assertSame('unverified_version', $advice->reason);
        }
        $current = self::table();
        $draft = self::addColumn(self::draftOf($current));
        $plan = (new SchemaChangePlanner())->plan(Engine::MySql, $current, $draft);
        self::assertSame('unverified_version', (new AlterAlgorithmAdvisor())->advise(Engine::MySql, null, $current, $draft, $plan)[0]->reason);
    }

    public function testATableThatIsNotInnoDbIsNeverClaimed(): void
    {
        foreach (['MyISAM', 'MEMORY', 'Aria'] as $storage) {
            $advice = self::advise(self::MYSQL, static fn (TableDraft $d) => self::addColumn($d), self::table($storage))[0];
            self::assertSame(AlterAlgorithm::Unknown, $advice->algorithm);
            self::assertSame('not_innodb', $advice->reason);
        }
        $unread = new TableDefinition('orders', TableKind::Table, [new ColumnDefinition('id', 'int', false, null, false, null)], [], [], null, 1);
        $draft = self::addColumn(self::draftOf($unread));
        $plan = (new SchemaChangePlanner())->plan(Engine::MySql, $unread, $draft);
        self::assertSame('not_innodb', (new AlterAlgorithmAdvisor())->advise(Engine::MySql, ServerVersion::parse('8.4.13'), $unread, $draft, $plan)[0]->reason);
    }

    public function testClickHouseGetsNothing(): void
    {
        $t = self::table();
        $current = new TableDefinition($t->name, $t->kind, $t->columns, [], [], null, $t->estimatedRows);
        $draft = self::addColumn(self::draftOf($current));
        $plan = (new SchemaChangePlanner())->plan(Engine::ClickHouse, $current, $draft);
        $advice = (new AlterAlgorithmAdvisor())->advise(Engine::ClickHouse, ServerVersion::parse('26.10.1.123'), $current, $draft, $plan);

        self::assertNotSame([], $advice);
        foreach ($advice as $one) {
            self::assertSame(AlterAlgorithm::Unknown, $one->algorithm);
            self::assertSame('unsupported_engine', $one->reason);
        }
    }

    public function testThereIsOneAdvicePerStatementInTheSameOrder(): void
    {
        $advice = self::advise(self::MARIADB, static fn (TableDraft $d) => self::addColumn(self::with($d, columns: array_values(array_filter($d->columns, static fn (ColumnDraft $c): bool => 'c' !== $c->name)), indexes: [...$d->indexes, new IndexDraft(null, 'k_a', ['a'], false, false, null, null)])));

        self::assertCount(3, $advice, 'drop column, add column, add index');
        self::assertSame([AlterAlgorithm::Instant, AlterAlgorithm::Instant, AlterAlgorithm::Inplace], array_map(static fn (AlgorithmAdvice $a): AlterAlgorithm => $a->algorithm, $advice));
    }

    public function testAnUnreadSizeGivesNoByteEstimate(): void
    {
        $t = self::table();
        $unsized = new TableDefinition($t->name, $t->kind, $t->columns, $t->indexes, $t->foreignKeys, null, 5, null, null, 'InnoDB', true);
        $advice = self::advise(self::MARIADB, static fn (TableDraft $d) => self::replaceColumn($d, 'n', 'bigint'), $unsized)[0];

        self::assertSame(AlterAlgorithm::Copy, $advice->algorithm);
        self::assertNull($advice->estimatedExtraBytes);
    }
}
