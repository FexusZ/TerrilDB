<?php

declare(strict_types=1);

namespace App\Tests\Core\Schema\Change;

use App\Core\Connection\Engine;
use App\Core\Schema\Change\ColumnDraft;
use App\Core\Schema\Change\Exception\UnsupportedPartitionChangeException;
use App\Core\Schema\Change\ForeignKeyDraft;
use App\Core\Schema\Change\IndexDraft;
use App\Core\Schema\Change\SchemaChangePlanner;
use App\Core\Schema\Change\TableDraft;
use App\Core\Schema\ColumnDefinition;
use App\Core\Schema\IndexDefinition;
use App\Core\Schema\PartitionDefinition;
use App\Core\Schema\PartitioningInfo;
use App\Core\Schema\PartitionMethod;
use App\Core\Schema\ReferentialAction;
use App\Core\Schema\TableDefinition;
use App\Core\Schema\TableKind;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PartitionedTableGuardTest extends TestCase
{
    private static function current(?PartitioningInfo $partitioning = null, bool $partitioned = true): TableDefinition
    {
        $partitioning ??= new PartitioningInfo(PartitionMethod::Range, 'to_days(`created_on`)', null, null, [new PartitionDefinition('p0', 'LESS THAN (738000)', '738000', false, null, null)]);

        return new TableDefinition(
            'events',
            TableKind::Table,
            [new ColumnDefinition('id', 'int', false, null, false, null), new ColumnDefinition('created_on', 'date', false, null, false, null), new ColumnDefinition('label', 'varchar(20)', false, null, false, null)],
            [new IndexDefinition('PRIMARY', ['id', 'created_on'], true, true)],
            [],
            null,
            null,
            null,
            null,
            $partitioned ? $partitioning : null,
        );
    }

    /**
     * @param list<ColumnDraft>|null     $columns
     * @param list<IndexDraft>|null      $indexes
     * @param list<ForeignKeyDraft>|null $foreignKeys
     */
    private static function draft(?array $columns = null, ?array $indexes = null, ?array $foreignKeys = null, string $name = 'events'): TableDraft
    {
        return new TableDraft(
            $name,
            $columns ?? [new ColumnDraft('id', 'id', 'int', false, null, false), new ColumnDraft('created_on', 'created_on', 'date', false, null, false), new ColumnDraft('label', 'label', 'varchar(20)', false, null, false)],
            $indexes ?? [new IndexDraft('PRIMARY', 'PRIMARY', ['id', 'created_on'], true, true, null, null)],
            $foreignKeys ?? [],
        );
    }

    private function plan(Engine $engine, TableDefinition $current, TableDraft $draft): int
    {
        return \count((new SchemaChangePlanner())->plan($engine, $current, $draft)->statements);
    }

    #[DataProvider('refused')]
    public function testRefusesWhatWouldBreakThePartitioning(string $reason, TableDraft $draft, Engine $engine = Engine::MySql): void
    {
        try {
            $this->plan($engine, self::current(), $draft);
            self::fail('The change should have been refused.');
        } catch (UnsupportedPartitionChangeException $e) {
            self::assertSame($reason, $e->reason);
        }
    }

    /**
     * @return iterable<string, array{string, TableDraft, 2?: Engine}>
     */
    public static function refused(): iterable
    {
        yield 'drop the partition column' => ['partition_column', self::draft(
            [new ColumnDraft('id', 'id', 'int', false, null, false), new ColumnDraft('label', 'label', 'varchar(20)', false, null, false)],
            [new IndexDraft('PRIMARY', 'PRIMARY', ['id'], true, true, null, null)],
        )];
        yield 'rename the partition column' => ['partition_column', self::draft(
            [new ColumnDraft('id', 'id', 'int', false, null, false), new ColumnDraft('created_on', 'day', 'date', false, null, false), new ColumnDraft('label', 'label', 'varchar(20)', false, null, false)],
            [new IndexDraft('PRIMARY', 'PRIMARY', ['id', 'day'], true, true, null, null)],
        )];
        yield 'change the type of the partition column' => ['partition_column', self::draft(
            [new ColumnDraft('id', 'id', 'int', false, null, false), new ColumnDraft('created_on', 'created_on', 'datetime', false, null, false), new ColumnDraft('label', 'label', 'varchar(20)', false, null, false)],
        )];
        yield 'primary key without the partition column' => ['partition_unique_key', self::draft(null, [new IndexDraft('PRIMARY', 'PRIMARY', ['id'], true, true, null, null)])];
        yield 'new unique key without the partition column' => ['partition_unique_key', self::draft(null, [
            new IndexDraft('PRIMARY', 'PRIMARY', ['id', 'created_on'], true, true, null, null),
            new IndexDraft(null, 'uniq_label', ['label'], true, false, null, null),
        ])];
        yield 'foreign key on MySQL' => ['partition_foreign_key', self::draft(null, null, [new ForeignKeyDraft(null, 'fk_label', ['label'], 'labels', ['name'], ReferentialAction::NoAction, ReferentialAction::NoAction)])];
        yield 'foreign key on MariaDB' => ['partition_foreign_key', self::draft(null, null, [new ForeignKeyDraft(null, 'fk_label', ['label'], 'labels', ['name'], ReferentialAction::NoAction, ReferentialAction::NoAction)]), Engine::MariaDb];
    }

    public function testAllowsWhatLeavesThePartitioningAlone(): void
    {
        $engine = Engine::MySql;

        // Rien ne change, une colonne ajoutée, un index simple, un index unique qui contient la colonne, un renommage de la table.
        self::assertSame(0, $this->plan($engine, self::current(), self::draft()));
        self::assertGreaterThan(0, $this->plan($engine, self::current(), self::draft([
            new ColumnDraft('id', 'id', 'int', false, null, false), new ColumnDraft('created_on', 'created_on', 'date', false, null, false),
            new ColumnDraft('label', 'label', 'varchar(20)', false, null, false), new ColumnDraft(null, 'note', 'text', true, null, false),
        ])));
        self::assertGreaterThan(0, $this->plan($engine, self::current(), self::draft(null, [
            new IndexDraft('PRIMARY', 'PRIMARY', ['id', 'created_on'], true, true, null, null),
            new IndexDraft(null, 'idx_label', ['label'], false, false, null, null),
            new IndexDraft(null, 'uniq_label_day', ['label', 'created_on'], true, false, null, null),
        ])));
        self::assertGreaterThan(0, $this->plan($engine, self::current(), self::draft(name: 'events_archive')));
    }

    public function testPostgreSqlAllowsAForeignKeyOnAPartitionedTable(): void
    {
        $draft = self::draft(null, null, [new ForeignKeyDraft(null, 'fk_label', ['label'], 'labels', ['name'], ReferentialAction::NoAction, ReferentialAction::NoAction)]);

        self::assertGreaterThan(0, $this->plan(Engine::PostgreSql, self::current(), $draft));
    }

    public function testATableWithoutPartitionsIsNotConcerned(): void
    {
        $draft = self::draft(
            [new ColumnDraft('id', 'id', 'int', false, null, false), new ColumnDraft('label', 'label', 'varchar(20)', false, null, false)],
            [new IndexDraft('PRIMARY', 'PRIMARY', ['id'], true, true, null, null)],
        );

        self::assertGreaterThan(0, $this->plan(Engine::MySql, self::current(partitioned: false), $draft));
    }

    public function testKeyPartitioningWithoutColumnsFollowsThePrimaryKey(): void
    {
        $key = new PartitioningInfo(PartitionMethod::Key, PartitioningInfo::PRIMARY_KEY, null, null, [new PartitionDefinition('p0', null, null, false, null, null)]);
        $draft = self::draft(null, [new IndexDraft('PRIMARY', 'PRIMARY', ['id'], true, true, null, null)]);

        $this->expectException(UnsupportedPartitionChangeException::class);
        $this->plan(Engine::MySql, self::current($key), $draft);
    }

    public function testTheColumnsOfAnExpressionAreFoundByName(): void
    {
        // « created_on » est dans to_days(`created_on`), mais « label » n'est pas une colonne de partitionnement.
        $draft = self::draft(
            [new ColumnDraft('id', 'id', 'int', false, null, false), new ColumnDraft('created_on', 'created_on', 'date', false, null, false)],
        );

        self::assertGreaterThan(0, $this->plan(Engine::MySql, self::current(), $draft));
    }

    public function testDroppingTheTableIsNotARefusal(): void
    {
        self::assertSame(1, \count((new SchemaChangePlanner())->planDrop(Engine::MySql, self::current())->statements));
    }
}
