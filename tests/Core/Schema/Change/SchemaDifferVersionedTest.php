<?php

declare(strict_types=1);

namespace App\Tests\Core\Schema\Change;

use App\Core\Schema\Change\ColumnDraft;
use App\Core\Schema\Change\Exception\UnsupportedSchemaChangeException;
use App\Core\Schema\Change\IndexDraft;
use App\Core\Schema\Change\Operation\DropColumn;
use App\Core\Schema\Change\Operation\RenameColumn;
use App\Core\Schema\Change\SchemaDiffer;
use App\Core\Schema\Change\TableDraft;
use App\Core\Schema\ColumnDefinition;
use App\Core\Schema\IndexDefinition;
use App\Core\Schema\LockReason;
use App\Core\Schema\TableDefinition;
use App\Core\Schema\TableKind;
use PHPUnit\Framework\TestCase;

/**
 * Table versionnée (MariaDB) : l'éditeur ne touche jamais aux colonnes de période (début / fin de ligne) ni à leur place dans la clé
 * primaire, ce qui casserait l'historique ou retirerait le versionnement. Le reste de la table s'édite comme d'habitude.
 */
final class SchemaDifferVersionedTest extends TestCase
{
    private static function current(): TableDefinition
    {
        return new TableDefinition(
            'accounts',
            TableKind::Table,
            [
                new ColumnDefinition('id', 'int(11)', false, null, false, null),
                new ColumnDefinition('name', 'varchar(40)', true, null, false, null),
                new ColumnDefinition('rs', 'timestamp(6)', false, null, false, null, lock: LockReason::SystemPeriod),
                new ColumnDefinition('re', 'timestamp(6)', false, null, false, null, lock: LockReason::SystemPeriod),
            ],
            [new IndexDefinition('PRIMARY', ['id', 're'], true, true)],
            [],
            null,
            10,
            versioned: true,
        );
    }

    /**
     * @param list<ColumnDraft>|null $columns
     * @param list<IndexDraft>|null  $indexes
     */
    private static function draft(?array $columns = null, ?array $indexes = null): TableDraft
    {
        return new TableDraft(
            'accounts',
            $columns ?? [
                new ColumnDraft('id', 'id', 'int(11)', false, null, false),
                new ColumnDraft('name', 'name', 'varchar(40)', true, null, false),
                new ColumnDraft('rs', 'rs', 'timestamp(6)', false, null, false),
                new ColumnDraft('re', 're', 'timestamp(6)', false, null, false),
            ],
            $indexes ?? [new IndexDraft('PRIMARY', 'PRIMARY', ['id', 're'], true, true, null, null)],
            [],
        );
    }

    public function testNothingChangedMeansNothingToDo(): void
    {
        self::assertSame([], (new SchemaDiffer())->diff(self::current(), self::draft()));
    }

    public function testDroppingAPeriodColumnIsRefused(): void
    {
        $columns = [
            new ColumnDraft('id', 'id', 'int(11)', false, null, false),
            new ColumnDraft('name', 'name', 'varchar(40)', true, null, false),
            new ColumnDraft('re', 're', 'timestamp(6)', false, null, false),
        ];

        $this->expectException(UnsupportedSchemaChangeException::class);
        (new SchemaDiffer())->diff(self::current(), self::draft($columns));
    }

    public function testRenamingAPeriodColumnIsRefused(): void
    {
        $columns = [
            new ColumnDraft('id', 'id', 'int(11)', false, null, false),
            new ColumnDraft('name', 'name', 'varchar(40)', true, null, false),
            new ColumnDraft('rs', 'rs', 'timestamp(6)', false, null, false),
            new ColumnDraft('re', 'ends', 'timestamp(6)', false, null, false),
        ];

        try {
            (new SchemaDiffer())->diff(self::current(), self::draft($columns, [new IndexDraft('PRIMARY', 'PRIMARY', ['id', 'ends'], true, true, null, null)]));
            self::fail('Renaming a period column should be refused.');
        } catch (UnsupportedSchemaChangeException $e) {
            self::assertSame(LockReason::SystemPeriod, $e->reason);
            self::assertSame('re', $e->subject);
        }
    }

    public function testTakingAPeriodColumnOutOfThePrimaryKeyIsRefused(): void
    {
        try {
            (new SchemaDiffer())->diff(self::current(), self::draft(null, [new IndexDraft('PRIMARY', 'PRIMARY', ['id'], true, true, null, null)]));
            self::fail('The period column must stay in the primary key.');
        } catch (UnsupportedSchemaChangeException $e) {
            self::assertSame(LockReason::SystemPeriod, $e->reason);
            self::assertSame('re', $e->subject);
        }
    }

    public function testAnOrdinaryColumnOfAVersionedTableStaysEditable(): void
    {
        $columns = [
            new ColumnDraft('id', 'id', 'int(11)', false, null, false),
            new ColumnDraft('name', 'label', 'varchar(40)', true, null, false),
            new ColumnDraft('rs', 'rs', 'timestamp(6)', false, null, false),
            new ColumnDraft('re', 're', 'timestamp(6)', false, null, false),
        ];

        $operations = (new SchemaDiffer())->diff(self::current(), self::draft($columns));

        self::assertCount(1, $operations);
        self::assertInstanceOf(RenameColumn::class, $operations[0]);
    }

    public function testDroppingAnOrdinaryColumnIsStillADrop(): void
    {
        $columns = [
            new ColumnDraft('id', 'id', 'int(11)', false, null, false),
            new ColumnDraft('rs', 'rs', 'timestamp(6)', false, null, false),
            new ColumnDraft('re', 're', 'timestamp(6)', false, null, false),
        ];

        $operations = (new SchemaDiffer())->diff(self::current(), self::draft($columns));

        self::assertInstanceOf(DropColumn::class, $operations[0]);
    }
}
