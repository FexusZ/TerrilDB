<?php

declare(strict_types=1);

namespace App\Tests\Core\Schema\Change;

use App\Core\Schema\Change\ColumnDraft;
use App\Core\Schema\Change\Exception\UnsupportedSchemaChangeException;
use App\Core\Schema\Change\IndexDraft;
use App\Core\Schema\Change\Operation\DropColumn;
use App\Core\Schema\Change\Operation\DropIndex;
use App\Core\Schema\Change\Operation\RenameColumn;
use App\Core\Schema\Change\Operation\RenameIndex;
use App\Core\Schema\Change\SchemaDiffer;
use App\Core\Schema\Change\TableDraft;
use App\Core\Schema\ColumnDefinition;
use App\Core\Schema\IndexDefinition;
use App\Core\Schema\LockReason;
use App\Core\Schema\TableDefinition;
use App\Core\Schema\TableKind;
use PHPUnit\Framework\TestCase;

/**
 * Une colonne ou un index que le modèle ne sait pas redire (colonne générée, index invisible…) ne se redéfinit jamais :
 * on refuse plutôt que de le dégrader. Renommer ou supprimer ne redit rien, donc reste permis.
 */
final class SchemaDifferLockTest extends TestCase
{
    private static function current(): TableDefinition
    {
        return new TableDefinition(
            'orders',
            TableKind::Table,
            [
                new ColumnDefinition('id', 'int', false, null, false, null),
                new ColumnDefinition('qty', 'int', false, null, false, null),
                new ColumnDefinition('double_qty', 'int', true, null, false, null, lock: LockReason::Generated),
            ],
            [
                new IndexDefinition('PRIMARY', ['id'], true, true),
                new IndexDefinition('idx_qty', ['qty'], false, false, lock: LockReason::IndexInvisible),
            ],
            [],
            null,
            10,
        );
    }

    private static function same(): TableDraft
    {
        return new TableDraft(
            'orders',
            [
                new ColumnDraft('id', 'id', 'int', false, null, false),
                new ColumnDraft('qty', 'qty', 'int', false, null, false),
                new ColumnDraft('double_qty', 'double_qty', 'int', true, null, false),
            ],
            [
                new IndexDraft('PRIMARY', 'PRIMARY', ['id'], true, true, null, null),
                new IndexDraft('idx_qty', 'idx_qty', ['qty'], false, false, null, null),
            ],
            [],
        );
    }

    public function testUntouchedLockedObjectsChangeNothing(): void
    {
        self::assertSame([], (new SchemaDiffer())->diff(self::current(), self::same()));
    }

    public function testModifyingALockedColumnIsRefused(): void
    {
        $draft = self::same();
        $draft = $draft->withColumns([$draft->columns[0], $draft->columns[1], new ColumnDraft('double_qty', 'double_qty', 'bigint', true, null, false)]);

        try {
            (new SchemaDiffer())->diff(self::current(), $draft);
            self::fail('A generated column must not be restated.');
        } catch (UnsupportedSchemaChangeException $e) {
            self::assertSame(LockReason::Generated, $e->reason);
            self::assertSame('double_qty', $e->subject);
        }
    }

    public function testRenamingOrDroppingALockedColumnIsAllowed(): void
    {
        $draft = self::same();
        $renamed = $draft->withColumns([$draft->columns[0], $draft->columns[1], new ColumnDraft('double_qty', 'twice', 'int', true, null, false)]);
        $operations = (new SchemaDiffer())->diff(self::current(), $renamed);
        self::assertCount(1, $operations);
        self::assertInstanceOf(RenameColumn::class, $operations[0]);

        $dropped = $draft->withColumns([$draft->columns[0], $draft->columns[1]]);
        $operations = (new SchemaDiffer())->diff(self::current(), $dropped);
        self::assertCount(1, $operations);
        self::assertInstanceOf(DropColumn::class, $operations[0]);
    }

    public function testRedefiningALockedIndexIsRefused(): void
    {
        $draft = self::same();
        $draft = $draft->withIndexes([$draft->indexes[0], new IndexDraft('idx_qty', 'idx_qty', ['qty', 'id'], false, false, null, null)]);

        try {
            (new SchemaDiffer())->diff(self::current(), $draft);
            self::fail('An invisible index must not be recreated.');
        } catch (UnsupportedSchemaChangeException $e) {
            self::assertSame(LockReason::IndexInvisible, $e->reason);
            self::assertSame('idx_qty', $e->subject);
        }
    }

    public function testRenamingOrDroppingALockedIndexIsAllowed(): void
    {
        $draft = self::same();
        $renamed = $draft->withIndexes([$draft->indexes[0], new IndexDraft('idx_qty', 'idx_quantity', ['qty'], false, false, null, null)]);
        $operations = (new SchemaDiffer())->diff(self::current(), $renamed);
        self::assertCount(1, $operations);
        self::assertInstanceOf(RenameIndex::class, $operations[0]);

        $operations = (new SchemaDiffer())->diff(self::current(), $draft->withIndexes([$draft->indexes[0]]));
        self::assertCount(1, $operations);
        self::assertInstanceOf(DropIndex::class, $operations[0]);
    }
}
