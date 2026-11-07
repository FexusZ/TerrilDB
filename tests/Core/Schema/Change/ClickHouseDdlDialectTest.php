<?php

declare(strict_types=1);

namespace App\Tests\Core\Schema\Change;

use App\Core\Schema\Change\ColumnDraft;
use App\Core\Schema\Change\Dialect\ClickHouseDdlDialect;
use App\Core\Schema\Change\Exception\InvalidSchemaChangeException;
use App\Core\Schema\Change\ForeignKeyDraft;
use App\Core\Schema\Change\IndexDraft;
use App\Core\Schema\Change\Operation\AddColumn;
use App\Core\Schema\Change\Operation\AddForeignKey;
use App\Core\Schema\Change\Operation\AddIndex;
use App\Core\Schema\Change\Operation\DropColumn;
use App\Core\Schema\Change\Operation\DropIndex;
use App\Core\Schema\Change\Operation\DropTable;
use App\Core\Schema\Change\Operation\ModifyColumn;
use App\Core\Schema\Change\Operation\RenameColumn;
use App\Core\Schema\Change\Operation\RenameIndex;
use App\Core\Schema\Change\Operation\RenameTable;
use App\Core\Schema\Change\Operation\SetPrimaryKey;
use App\Core\Schema\Change\TableDraft;
use App\Core\Schema\ColumnDefinition;
use App\Core\Schema\IndexDefinition;
use App\Core\Schema\ReferentialAction;
use App\Core\Schema\TableDefinition;
use App\Core\Schema\TableKind;
use PHPUnit\Framework\TestCase;

final class ClickHouseDdlDialectTest extends TestCase
{
    /**
     * @param list<object> $operations
     *
     * @return list<string>
     */
    private static function sql(array $operations): array
    {
        return array_map(static fn ($statement): string => $statement->sql, (new ClickHouseDdlDialect())->statements('events', $operations));
    }

    public function testQuotesWithBackticksAndEscapesWithBackslashes(): void
    {
        self::assertSame(
            ['ALTER TABLE `events` ADD COLUMN `we\\`ird` String DEFAULT \'a\\\\b\\\'c\''],
            self::sql([new AddColumn(new ColumnDraft(null, 'we`ird', 'String', false, "a\\b'c", false))]),
        );
    }

    public function testColumns(): void
    {
        self::assertSame(['ALTER TABLE `events` ADD COLUMN `n` UInt32 DEFAULT 0'], self::sql([new AddColumn(new ColumnDraft(null, 'n', 'UInt32', false, '0', false))]));
        self::assertSame(['ALTER TABLE `events` DROP COLUMN `n`'], self::sql([new DropColumn('n')]));
        self::assertSame(['ALTER TABLE `events` RENAME COLUMN `a` TO `b`'], self::sql([new RenameColumn('a', 'b')]));
    }

    public function testModifyingAColumnRestatesItsTypeAndDefault(): void
    {
        $before = new ColumnDefinition('n', 'UInt32', false, '0', false, null);

        self::assertSame(
            ['ALTER TABLE `events` MODIFY COLUMN `n` UInt64 DEFAULT 1'],
            self::sql([new ModifyColumn($before, new ColumnDraft('n', 'n', 'UInt64', false, '1', false), true, false, true, false)]),
        );
    }

    public function testRemovingADefaultIsAnExplicitRemove(): void
    {
        $before = new ColumnDefinition('n', 'UInt32', false, '0', false, null);

        self::assertSame(
            ['ALTER TABLE `events` MODIFY COLUMN `n` UInt32', 'ALTER TABLE `events` MODIFY COLUMN `n` REMOVE DEFAULT'],
            self::sql([new ModifyColumn($before, new ColumnDraft('n', 'n', 'UInt32', false, null, false), false, false, true, false)]),
        );
    }

    public function testASkippingIndexIsAddedThenMaterializedForExistingData(): void
    {
        self::assertSame(
            ['ALTER TABLE `events` ADD INDEX `idx` `a` TYPE minmax GRANULARITY 1', 'ALTER TABLE `events` MATERIALIZE INDEX `idx`'],
            self::sql([new AddIndex(new IndexDraft(null, 'idx', ['a'], false, false, null, null))]),
        );
        self::assertSame(
            ['ALTER TABLE `events` ADD INDEX `idx` (`a`, `b`) TYPE bloom_filter GRANULARITY 4', 'ALTER TABLE `events` MATERIALIZE INDEX `idx`'],
            self::sql([new AddIndex(new IndexDraft(null, 'idx', ['a', 'b'], false, false, 'bloom_filter', 4))]),
        );
        self::assertSame(['ALTER TABLE `events` DROP INDEX `idx`'], self::sql([new DropIndex('idx', null)]));
    }

    public function testRefusesWhatClickHouseDoesNotHave(): void
    {
        foreach ([
            [new SetPrimaryKey(null, new IndexDraft(null, 'PRIMARY', ['a'], true, true, null, null))],
            [new AddForeignKey(new ForeignKeyDraft(null, 'fk', ['a'], 't', ['id'], ReferentialAction::NoAction, ReferentialAction::NoAction))],
            [new RenameIndex('a', 'b')],
            [new AddIndex(new IndexDraft(null, 'u', ['a'], true, false, null, null))],
            [new AddColumn(new ColumnDraft(null, 'id', 'UInt32', false, null, true))],
        ] as $operations) {
            try {
                self::sql($operations);
                self::fail('Expected a refusal for '.$operations[0]::class);
            } catch (InvalidSchemaChangeException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testRenamesAndDropsTheTable(): void
    {
        self::assertSame(['RENAME TABLE `events` TO `log`'], self::sql([new RenameTable('events', 'log')]));
        self::assertSame(['DROP TABLE `events`'], self::sql([new DropTable('events')]));
    }

    public function testNotTransactional(): void
    {
        self::assertFalse((new ClickHouseDdlDialect())->transactional());
    }

    private static function current(): TableDefinition
    {
        return new TableDefinition(
            'events',
            TableKind::Table,
            [new ColumnDefinition('id', 'UInt64', false, null, false, null), new ColumnDefinition('note', 'Nullable(String)', true, null, false, null)],
            [new IndexDefinition('ORDER BY', ['id'], false, false), new IndexDefinition('idx_note', ['note'], false, false)],
            [],
            null,
            null,
        );
    }

    public function testNullabilityLivesInTheTypeSoTheDraftIsRewrittenToSayItThere(): void
    {
        $draft = new TableDraft('events', [
            new ColumnDraft('id', 'id', 'UInt64', true, null, false),
            new ColumnDraft('note', 'note', 'Nullable(String)', false, null, false),
        ], [new IndexDraft('ORDER BY', 'ORDER BY', ['id'], false, false, null, null), new IndexDraft('idx_note', 'idx_note', ['note'], false, false, null, null)], []);

        $prepared = (new ClickHouseDdlDialect())->prepare(self::current(), $draft);

        self::assertSame(['Nullable(UInt64)', 'String'], array_map(static fn (ColumnDraft $c): string => $c->type, $prepared->columns));
    }

    public function testNullableIsInsertedInsideLowCardinality(): void
    {
        $draft = new TableDraft('events', [
            new ColumnDraft('id', 'id', 'LowCardinality(String)', true, null, false),
            new ColumnDraft('note', 'note', 'Nullable(String)', true, null, false),
        ], [new IndexDraft('ORDER BY', 'ORDER BY', ['id'], false, false, null, null), new IndexDraft('idx_note', 'idx_note', ['note'], false, false, null, null)], []);

        $prepared = (new ClickHouseDdlDialect())->prepare(self::current(), $draft);

        self::assertSame('LowCardinality(Nullable(String))', $prepared->columns[0]->type);
    }

    public function testTheSortKeyCannotBeEdited(): void
    {
        $draft = new TableDraft('events', [new ColumnDraft('id', 'id', 'UInt64', false, null, false), new ColumnDraft('note', 'note', 'Nullable(String)', true, null, false)], [new IndexDraft('idx_note', 'idx_note', ['note'], false, false, null, null)], []);

        $this->expectException(InvalidSchemaChangeException::class);

        (new ClickHouseDdlDialect())->prepare(self::current(), $draft);
    }

    public function testForeignKeysAndPrimaryKeysAreRefusedInTheDraftItself(): void
    {
        $sortKey = new IndexDraft('ORDER BY', 'ORDER BY', ['id'], false, false, null, null);
        $columns = [new ColumnDraft('id', 'id', 'UInt64', false, null, false), new ColumnDraft('note', 'note', 'Nullable(String)', true, null, false)];
        $note = new IndexDraft('idx_note', 'idx_note', ['note'], false, false, null, null);

        foreach ([
            new TableDraft('events', $columns, [$sortKey, $note], [new ForeignKeyDraft(null, 'fk', ['id'], 't', ['id'], ReferentialAction::NoAction, ReferentialAction::NoAction)]),
            new TableDraft('events', $columns, [$sortKey, $note, new IndexDraft(null, 'pk', ['id'], true, true, null, null)], []),
        ] as $draft) {
            try {
                (new ClickHouseDdlDialect())->prepare(self::current(), $draft);
                self::fail('Expected a refusal.');
            } catch (InvalidSchemaChangeException) {
                $this->addToAssertionCount(1);
            }
        }
    }
}
