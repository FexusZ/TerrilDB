<?php

declare(strict_types=1);

namespace App\Tests\Core\Schema\Change;

use App\Core\Schema\Change\ColumnDraft;
use App\Core\Schema\Change\ForeignKeyDraft;
use App\Core\Schema\Change\IndexDraft;
use App\Core\Schema\Change\Operation\AddColumn;
use App\Core\Schema\Change\Operation\AddForeignKey;
use App\Core\Schema\Change\Operation\AddIndex;
use App\Core\Schema\Change\Operation\DropColumn;
use App\Core\Schema\Change\Operation\DropForeignKey;
use App\Core\Schema\Change\Operation\DropIndex;
use App\Core\Schema\Change\Operation\ModifyColumn;
use App\Core\Schema\Change\Operation\RenameColumn;
use App\Core\Schema\Change\Operation\RenameIndex;
use App\Core\Schema\Change\Operation\RenameTable;
use App\Core\Schema\Change\Operation\SetPrimaryKey;
use App\Core\Schema\Change\SchemaDiffer;
use App\Core\Schema\Change\TableDraft;
use App\Core\Schema\ColumnDefinition;
use App\Core\Schema\ForeignKeyDefinition;
use App\Core\Schema\IndexDefinition;
use App\Core\Schema\ReferentialAction;
use App\Core\Schema\TableDefinition;
use App\Core\Schema\TableKind;
use PHPUnit\Framework\TestCase;

final class SchemaDifferTest extends TestCase
{
    private static function column(string $name, string $type = 'integer', bool $nullable = false, ?string $default = null, bool $auto = false): ColumnDefinition
    {
        return new ColumnDefinition($name, $type, $nullable, $default, $auto, null);
    }

    private static function current(): TableDefinition
    {
        return new TableDefinition(
            'orders',
            TableKind::Table,
            [self::column('id', auto: true), self::column('customer_id'), self::column('status', 'varchar(20)', default: 'new')],
            [new IndexDefinition('PRIMARY', ['id'], true, true), new IndexDefinition('idx_status', ['status'], false, false)],
            [new ForeignKeyDefinition('fk_customer', ['customer_id'], 'customers', ['id'], ReferentialAction::NoAction, ReferentialAction::Cascade)],
            null,
            10,
        );
    }

    /** Le brouillon identique à la table actuelle : rien ne change. */
    private static function same(): TableDraft
    {
        return new TableDraft(
            'orders',
            [new ColumnDraft('id', 'id', 'integer', false, null, true), new ColumnDraft('customer_id', 'customer_id', 'integer', false, null, false), new ColumnDraft('status', 'status', 'varchar(20)', false, 'new', false)],
            [new IndexDraft('PRIMARY', 'PRIMARY', ['id'], true, true, null, null), new IndexDraft('idx_status', 'idx_status', ['status'], false, false, null, null)],
            [new ForeignKeyDraft('fk_customer', 'fk_customer', ['customer_id'], 'customers', ['id'], ReferentialAction::NoAction, ReferentialAction::Cascade)],
        );
    }

    /**
     * @param callable(TableDraft): TableDraft $edit
     *
     * @return list<object>
     */
    private static function diff(callable $edit): array
    {
        return (new SchemaDiffer())->diff(self::current(), $edit(self::same()));
    }

    public function testNothingChangesWhenTheDraftIsTheTable(): void
    {
        self::assertSame([], self::diff(static fn (TableDraft $draft): TableDraft => $draft));
    }

    public function testRenamesTheTable(): void
    {
        $operations = self::diff(static fn (TableDraft $d): TableDraft => $d->withName('sales'));

        self::assertCount(1, $operations);
        self::assertInstanceOf(RenameTable::class, $operations[0]);
        self::assertSame(['orders', 'sales'], [$operations[0]->from, $operations[0]->to]);
    }

    public function testAddsAColumnThatHasNoOriginalName(): void
    {
        $operations = self::diff(static fn (TableDraft $d): TableDraft => $d->withColumns([...$d->columns, new ColumnDraft(null, 'note', 'text', true, null, false)]));

        self::assertCount(1, $operations);
        self::assertInstanceOf(AddColumn::class, $operations[0]);
        self::assertSame('note', $operations[0]->column->name);
    }

    public function testDropsAColumnThatIsNoLongerThere(): void
    {
        $operations = self::diff(static fn (TableDraft $d): TableDraft => new TableDraft($d->name, [$d->columns[0], $d->columns[1]], [$d->indexes[0]], $d->foreignKeys));

        $drops = array_values(array_filter($operations, static fn (object $o): bool => $o instanceof DropColumn));
        self::assertCount(1, $drops);
        self::assertSame('status', $drops[0]->name);
    }

    public function testRenamesAColumnAndKeepsItsDataAndItsIndexes(): void
    {
        $operations = self::diff(static fn (TableDraft $d): TableDraft => new TableDraft(
            $d->name,
            [$d->columns[0], $d->columns[1], new ColumnDraft('status', 'state', 'varchar(20)', false, 'new', false)],
            [$d->indexes[0], new IndexDraft('idx_status', 'idx_status', ['state'], false, false, null, null)],
            $d->foreignKeys,
        ));

        self::assertCount(1, $operations, 'a rename moves the index along, no drop and re-create');
        self::assertInstanceOf(RenameColumn::class, $operations[0]);
        self::assertSame(['status', 'state'], [$operations[0]->from, $operations[0]->to]);
    }

    public function testModifiesTheTypeTheNullabilityAndTheDefaultOfAColumn(): void
    {
        $operations = self::diff(static fn (TableDraft $d): TableDraft => $d->withColumns([$d->columns[0], $d->columns[1], new ColumnDraft('status', 'status', 'varchar(40)', true, 'old', false)]));

        self::assertCount(1, $operations);
        self::assertInstanceOf(ModifyColumn::class, $operations[0]);
        self::assertTrue($operations[0]->typeChanged);
        self::assertTrue($operations[0]->nullabilityChanged);
        self::assertTrue($operations[0]->defaultChanged);
        self::assertFalse($operations[0]->autoIncrementChanged);
    }

    public function testAnEngineDefaultWrittenWithACastEqualsTheSameDefaultTyped(): void
    {
        $current = new TableDefinition('t', TableKind::Table, [self::column('s', 'character varying(20)', default: "'new'::character varying")], [], [], null, null);
        $draft = new TableDraft('t', [new ColumnDraft('s', 's', 'CHARACTER VARYING(20)', false, 'new', false)], [], []);

        self::assertSame([], (new SchemaDiffer())->diff($current, $draft));
    }

    public function testDropsAndAddsIndexesButMovesNoneThatOnlyChangedName(): void
    {
        $operations = self::diff(static fn (TableDraft $d): TableDraft => $d->withIndexes([
            $d->indexes[0],
            new IndexDraft('idx_status', 'idx_state_renamed', ['status'], false, false, null, null),
            new IndexDraft(null, 'idx_new', ['customer_id', 'status'], true, false, null, null),
        ]));

        self::assertCount(2, $operations);
        self::assertInstanceOf(RenameIndex::class, $operations[0]);
        self::assertInstanceOf(AddIndex::class, $operations[1]);
        self::assertTrue($operations[1]->index->unique);
    }

    public function testChangingTheColumnsOfAnIndexDropsAndRecreatesIt(): void
    {
        $operations = self::diff(static fn (TableDraft $d): TableDraft => $d->withIndexes([$d->indexes[0], new IndexDraft('idx_status', 'idx_status', ['status', 'customer_id'], false, false, null, null)]));

        self::assertCount(2, $operations);
        self::assertInstanceOf(DropIndex::class, $operations[0]);
        self::assertInstanceOf(AddIndex::class, $operations[1]);
    }

    public function testRemovingAnIndexDropsIt(): void
    {
        $operations = self::diff(static fn (TableDraft $d): TableDraft => $d->withIndexes([$d->indexes[0]]));

        self::assertCount(1, $operations);
        self::assertInstanceOf(DropIndex::class, $operations[0]);
        self::assertSame('idx_status', $operations[0]->name);
    }

    public function testChangesThePrimaryKey(): void
    {
        $operations = self::diff(static fn (TableDraft $d): TableDraft => $d->withIndexes([new IndexDraft('PRIMARY', 'PRIMARY', ['id', 'customer_id'], true, true, null, null), $d->indexes[1]]));

        self::assertCount(1, $operations);
        self::assertInstanceOf(SetPrimaryKey::class, $operations[0]);
        self::assertSame(['id', 'customer_id'], $operations[0]->new?->columns);
        self::assertSame(['id'], $operations[0]->old?->columns);
    }

    public function testRemovesThePrimaryKey(): void
    {
        $operations = self::diff(static fn (TableDraft $d): TableDraft => $d->withIndexes([$d->indexes[1]]));

        self::assertCount(1, $operations);
        self::assertInstanceOf(SetPrimaryKey::class, $operations[0]);
        self::assertNull($operations[0]->new);
    }

    public function testDropsAndAddsForeignKeys(): void
    {
        $operations = self::diff(static fn (TableDraft $d): TableDraft => $d->withForeignKeys([new ForeignKeyDraft(null, 'fk_new', ['status'], 'statuses', ['code'], ReferentialAction::Restrict, ReferentialAction::SetNull)]));

        self::assertCount(2, $operations);
        self::assertInstanceOf(DropForeignKey::class, $operations[0]);
        self::assertInstanceOf(AddForeignKey::class, $operations[1]);
    }

    public function testChangingAForeignKeyActionDropsAndRecreatesIt(): void
    {
        $operations = self::diff(static fn (TableDraft $d): TableDraft => $d->withForeignKeys([new ForeignKeyDraft('fk_customer', 'fk_customer', ['customer_id'], 'customers', ['id'], ReferentialAction::NoAction, ReferentialAction::Restrict)]));

        self::assertCount(2, $operations);
        self::assertInstanceOf(DropForeignKey::class, $operations[0]);
        self::assertInstanceOf(AddForeignKey::class, $operations[1]);
    }
}
