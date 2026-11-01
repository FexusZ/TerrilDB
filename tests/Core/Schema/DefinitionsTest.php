<?php

declare(strict_types=1);

namespace App\Tests\Core\Schema;

use App\Core\Schema\ColumnDefinition;
use App\Core\Schema\Exception\InvalidSchemaException;
use App\Core\Schema\ForeignKeyDefinition;
use App\Core\Schema\IndexDefinition;
use App\Core\Schema\ReferentialAction;
use App\Core\Schema\TableDefinition;
use App\Core\Schema\TableKind;
use App\Core\Schema\TableSummary;
use PHPUnit\Framework\TestCase;

final class DefinitionsTest extends TestCase
{
    private function column(string $name = 'id', bool $nullable = false): ColumnDefinition
    {
        return new ColumnDefinition($name, 'integer', $nullable, null, false, null);
    }

    private function table(): TableDefinition
    {
        return new TableDefinition(
            'orders',
            TableKind::Table,
            [$this->column('id'), $this->column('customer_id')],
            [new IndexDefinition('orders_pkey', ['id'], true, true), new IndexDefinition('idx_customer', ['customer_id'], false, false)],
            [new ForeignKeyDefinition('fk_customer', ['customer_id'], 'customers', ['id'], ReferentialAction::NoAction, ReferentialAction::Cascade)],
            null,
            120,
        );
    }

    public function testASummaryKeepsItsFields(): void
    {
        $summary = new TableSummary('orders', TableKind::View, null);

        self::assertSame('orders', $summary->name);
        self::assertSame(TableKind::View, $summary->kind);
        self::assertNull($summary->estimatedRows);
    }

    public function testASummaryRefusesAnEmptyNameOrANegativeEstimate(): void
    {
        foreach ([static fn () => new TableSummary('', TableKind::Table, 1), static fn () => new TableSummary('t', TableKind::Table, -1)] as $build) {
            try {
                $build();
                self::fail('Expected a refusal.');
            } catch (InvalidSchemaException) {
            }
        }
        $this->addToAssertionCount(1);
    }

    public function testAColumnKeepsItsFields(): void
    {
        $column = new ColumnDefinition('status', 'varchar(20)', true, "'new'", false, 'order state');

        self::assertSame('status', $column->name);
        self::assertSame('varchar(20)', $column->type);
        self::assertTrue($column->nullable);
        self::assertSame("'new'", $column->default);
        self::assertFalse($column->autoIncrement);
        self::assertSame('order state', $column->comment);
    }

    public function testAColumnNeedsANameAndAType(): void
    {
        $this->expectException(InvalidSchemaException::class);

        new ColumnDefinition('', 'integer', false, null, false, null);
    }

    public function testAColumnNeedsAType(): void
    {
        $this->expectException(InvalidSchemaException::class);

        new ColumnDefinition('id', '', false, null, false, null);
    }

    public function testAPrimaryIndexIsUnique(): void
    {
        $this->expectException(InvalidSchemaException::class);

        new IndexDefinition('PRIMARY', ['id'], false, true);
    }

    public function testAnIndexNeedsColumns(): void
    {
        $this->expectException(InvalidSchemaException::class);

        new IndexDefinition('idx', [], false, false);
    }

    public function testAnIndexNeedsAName(): void
    {
        $this->expectException(InvalidSchemaException::class);

        new IndexDefinition('', ['id'], false, false);
    }

    public function testAForeignKeyNeedsAsManyReferencedColumnsAsColumns(): void
    {
        $this->expectException(InvalidSchemaException::class);

        new ForeignKeyDefinition('fk', ['a', 'b'], 'other', ['id'], ReferentialAction::NoAction, ReferentialAction::NoAction);
    }

    public function testAForeignKeyNeedsColumnsAndATarget(): void
    {
        foreach ([
            static fn () => new ForeignKeyDefinition('fk', [], 'other', [], ReferentialAction::NoAction, ReferentialAction::NoAction),
            static fn () => new ForeignKeyDefinition('fk', ['a'], '', ['id'], ReferentialAction::NoAction, ReferentialAction::NoAction),
            static fn () => new ForeignKeyDefinition('', ['a'], 'other', ['id'], ReferentialAction::NoAction, ReferentialAction::NoAction),
        ] as $build) {
            try {
                $build();
                self::fail('Expected a refusal.');
            } catch (InvalidSchemaException) {
            }
        }
        $this->addToAssertionCount(1);
    }

    public function testATableFindsItsColumnAndItsPrimaryKey(): void
    {
        $table = $this->table();

        self::assertSame('customer_id', $table->column('customer_id')?->name);
        self::assertNull($table->column('missing'));
        self::assertSame('orders_pkey', $table->primaryKey()?->name);
        self::assertSame(120, $table->estimatedRows);
    }

    public function testATableWithoutPrimaryKeyHasNone(): void
    {
        $table = new TableDefinition('log', TableKind::Table, [$this->column()], [], [], null, null);

        self::assertNull($table->primaryKey());
    }

    public function testOnlyAViewCarriesADefinition(): void
    {
        $view = new TableDefinition('v', TableKind::View, [$this->column()], [], [], 'SELECT 1', null);
        self::assertSame('SELECT 1', $view->viewDefinition);

        $this->expectException(InvalidSchemaException::class);
        new TableDefinition('t', TableKind::Table, [$this->column()], [], [], 'SELECT 1', null);
    }

    public function testATableNeedsANameAndAtLeastOneColumn(): void
    {
        foreach ([
            fn () => new TableDefinition('', TableKind::Table, [$this->column()], [], [], null, null),
            static fn () => new TableDefinition('t', TableKind::Table, [], [], [], null, null),
        ] as $build) {
            try {
                $build();
                self::fail('Expected a refusal.');
            } catch (InvalidSchemaException) {
            }
        }
        $this->addToAssertionCount(1);
    }

    public function testDuplicateColumnNamesAreRefused(): void
    {
        $this->expectException(InvalidSchemaException::class);

        new TableDefinition('t', TableKind::Table, [$this->column('id'), $this->column('id')], [], [], null, null);
    }

    public function testKindsHaveStableValues(): void
    {
        self::assertSame('table', TableKind::Table->value);
        self::assertSame('view', TableKind::View->value);
        self::assertSame('materialized_view', TableKind::MaterializedView->value);
    }
}
