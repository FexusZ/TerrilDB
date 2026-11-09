<?php

declare(strict_types=1);

namespace App\Tests\Core\Data;

use App\Core\Connection\Engine;
use App\Core\Data\DataChangePlanner;
use App\Core\Data\DataDraft;
use App\Core\Data\Exception\InvalidDataChangeException;
use App\Core\Data\RowDelete;
use App\Core\Data\RowInsert;
use App\Core\Data\RowUpdate;
use App\Core\Schema\ColumnDefinition;
use App\Core\Schema\IndexDefinition;
use App\Core\Schema\TableDefinition;
use App\Core\Schema\TableKind;
use PHPUnit\Framework\TestCase;

final class DataChangePlannerTest extends TestCase
{
    private static function col(string $name, string $type = 'integer', bool $nullable = false): ColumnDefinition
    {
        return new ColumnDefinition($name, $type, $nullable, null, false, null);
    }

    private static function orders(bool $primary = true, string $note = 'text'): TableDefinition
    {
        return new TableDefinition(
            'orders',
            TableKind::Table,
            [self::col('id'), self::col('status', 'text'), self::col('note', $note, true)],
            $primary ? [new IndexDefinition('orders_pkey', ['id'], true, true)] : [],
            [],
            null,
            10,
        );
    }

    public function testUpdateByPrimaryKeyChecksTheOldValueOfEveryChangedColumn(): void
    {
        $plan = (new DataChangePlanner())->plan(Engine::PostgreSql, self::orders(), new DataDraft(
            [new RowUpdate(['id' => 7, 'status' => 'new', 'note' => null], ['status' => 'paid', 'note' => 'ok'])],
            [],
            [],
        ));

        self::assertCount(1, $plan->statements);
        $statement = $plan->statements[0];
        self::assertSame('update', $statement->operation);
        self::assertSame('UPDATE "orders" SET "status" = ?, "note" = ? WHERE "id" = ? AND "status" = ? AND "note" IS NULL', $statement->sql);
        self::assertSame(['paid', 'ok', 7, 'new'], $statement->params);
        self::assertSame('SELECT count(*) FROM "orders" WHERE "id" = ? AND "status" = ? AND "note" IS NULL', $statement->matchSql);
        self::assertSame([7, 'new'], $statement->matchParams);
        self::assertSame("UPDATE \"orders\" SET \"status\" = 'paid', \"note\" = 'ok' WHERE \"id\" = 7 AND \"status\" = 'new' AND \"note\" IS NULL", $statement->display);
        self::assertFalse($statement->destructive);
        self::assertTrue($plan->transactional);
        self::assertFalse($plan->asynchronous);
    }

    public function testSettingNullIsWrittenAsNull(): void
    {
        $plan = (new DataChangePlanner())->plan(Engine::PostgreSql, self::orders(), new DataDraft(
            [new RowUpdate(['id' => 1, 'status' => 'a', 'note' => 'x'], ['note' => null])],
            [],
            [],
        ));

        self::assertSame('UPDATE "orders" SET "note" = NULL WHERE "id" = ? AND "note" = ?', $plan->statements[0]->sql);
        self::assertSame([1, 'x'], $plan->statements[0]->params);
    }

    public function testValuesThatDoNotChangeAreDropped(): void
    {
        $plan = (new DataChangePlanner())->plan(Engine::PostgreSql, self::orders(), new DataDraft(
            [new RowUpdate(['id' => 1, 'status' => 'a', 'note' => null], ['status' => 'a', 'note' => 'x'])],
            [],
            [],
        ));

        self::assertSame('UPDATE "orders" SET "note" = ? WHERE "id" = ? AND "note" IS NULL', $plan->statements[0]->sql);
    }

    public function testNumbersThatAreEqualButWrittenDifferentlyAreNotAChange(): void
    {
        $table = new TableDefinition(
            'prices',
            TableKind::Table,
            [self::col('id'), self::col('price', 'numeric(10,2)'), self::col('label', 'text')],
            [new IndexDefinition('prices_pkey', ['id'], true, true)],
            [],
            null,
            null,
        );

        $plan = (new DataChangePlanner())->plan(Engine::MySql, $table, new DataDraft(
            [new RowUpdate(['id' => 1, 'price' => '5.00', 'label' => 'a'], ['price' => '5.0', 'label' => 'b']), new RowUpdate(['id' => 2, 'price' => 5, 'label' => 'a'], ['price' => '+5.000'])],
            [],
            [],
        ));

        self::assertCount(1, $plan->statements);
        self::assertSame('UPDATE `prices` SET `label` = ? WHERE `id` = ? AND `label` = ?', $plan->statements[0]->sql);
    }

    public function testBigIntegersThatDifferByOneAreStillAChange(): void
    {
        $table = new TableDefinition('t', TableKind::Table, [self::col('id'), self::col('n', 'bigint')], [new IndexDefinition('t_pkey', ['id'], true, true)], [], null, null);

        $plan = (new DataChangePlanner())->plan(Engine::PostgreSql, $table, new DataDraft([new RowUpdate(['id' => 1, 'n' => '9007199254740993'], ['n' => '9007199254740992'])], [], []));

        self::assertCount(1, $plan->statements);
    }

    public function testARowWithNothingChangedIsSkipped(): void
    {
        $plan = (new DataChangePlanner())->plan(Engine::PostgreSql, self::orders(), new DataDraft(
            [new RowUpdate(['id' => 1, 'status' => 'a'], ['status' => 'a']), new RowUpdate(['id' => 2, 'status' => 'a'], ['status' => 'b'])],
            [],
            [],
        ));

        self::assertCount(1, $plan->statements);
    }

    public function testInsertListsOnlyTheGivenColumns(): void
    {
        $plan = (new DataChangePlanner())->plan(Engine::MySql, self::orders(), new DataDraft([], [new RowInsert(['status' => "o'k", 'note' => null])], []));

        $statement = $plan->statements[0];
        self::assertSame('insert', $statement->operation);
        self::assertSame('INSERT INTO `orders` (`status`, `note`) VALUES (?, NULL)', $statement->sql);
        self::assertSame(["o'k"], $statement->params);
        self::assertNull($statement->matchSql);
        self::assertSame("INSERT INTO `orders` (`status`, `note`) VALUES ('o\\'k', NULL)", $statement->display);
    }

    public function testDeleteByPrimaryKeyIsDestructive(): void
    {
        $plan = (new DataChangePlanner())->plan(Engine::MySql, self::orders(), new DataDraft([], [], [new RowDelete(['id' => 3, 'status' => 'x', 'note' => null])]));

        $statement = $plan->statements[0];
        self::assertSame('DELETE FROM `orders` WHERE `id` = ?', $statement->sql);
        self::assertSame([3], $statement->params);
        self::assertTrue($statement->destructive);
        self::assertTrue($plan->hasDestructive());
    }

    public function testWithoutPrimaryKeyTheRowIsFoundByAllItsComparableValues(): void
    {
        $plan = (new DataChangePlanner())->plan(Engine::PostgreSql, self::orders(false, 'jsonb'), new DataDraft(
            [],
            [],
            [new RowDelete(['id' => 3, 'status' => 'x', 'note' => '{"a":1}'])],
        ));

        self::assertSame('DELETE FROM "orders" WHERE "id" = ? AND "status" = ?', $plan->statements[0]->sql);
    }

    public function testWithoutPrimaryKeyTheWholeRowMustBeKnown(): void
    {
        $this->expectException(InvalidDataChangeException::class);

        (new DataChangePlanner())->plan(Engine::PostgreSql, self::orders(false), new DataDraft([], [], [new RowDelete(['id' => 3])]));
    }

    public function testThePrimaryKeyMustBeKnown(): void
    {
        $this->expectException(InvalidDataChangeException::class);

        (new DataChangePlanner())->plan(Engine::PostgreSql, self::orders(), new DataDraft([new RowUpdate(['status' => 'a'], ['status' => 'b'])], [], []));
    }

    public function testUnknownColumnsAreRefused(): void
    {
        $this->expectException(InvalidDataChangeException::class);

        (new DataChangePlanner())->plan(Engine::PostgreSql, self::orders(), new DataDraft([], [new RowInsert(['nope' => 1])], []));
    }

    public function testOnlyUnchangedRowsMeansNothingToDo(): void
    {
        $this->expectException(InvalidDataChangeException::class);

        (new DataChangePlanner())->plan(Engine::PostgreSql, self::orders(), new DataDraft([new RowUpdate(['id' => 1, 'status' => 'a'], ['status' => 'a'])], [], []));
    }

    public function testAnEmptyChangeIsRefused(): void
    {
        $this->expectException(InvalidDataChangeException::class);

        (new DataChangePlanner())->plan(Engine::PostgreSql, self::orders(), new DataDraft([new RowUpdate(['id' => 1], [])], [], []));
    }

    public function testAnEmptyInsertIsRefused(): void
    {
        $this->expectException(InvalidDataChangeException::class);

        (new DataChangePlanner())->plan(Engine::PostgreSql, self::orders(), new DataDraft([], [new RowInsert([])], []));
    }

    public function testOnlyTablesCanBeEdited(): void
    {
        $view = new TableDefinition('v', TableKind::View, [self::col('id')], [], [], 'select 1', null);

        $this->expectException(InvalidDataChangeException::class);

        (new DataChangePlanner())->plan(Engine::PostgreSql, $view, new DataDraft([], [new RowInsert(['id' => 1])], []));
    }

    public function testTheNumberOfChangesIsBounded(): void
    {
        $inserts = array_fill(0, 501, new RowInsert(['status' => 'a']));

        $this->expectException(InvalidDataChangeException::class);

        (new DataChangePlanner())->plan(Engine::PostgreSql, self::orders(), new DataDraft([], $inserts, []));
    }

    public function testNothingToDoIsRefused(): void
    {
        $this->expectException(InvalidDataChangeException::class);

        (new DataChangePlanner())->plan(Engine::PostgreSql, self::orders(), new DataDraft([], [], []));
    }

    public function testClickHouseWritesMutationsWithTypedParametersAndIsAsynchronous(): void
    {
        $table = new TableDefinition(
            'events',
            TableKind::Table,
            [self::col('id', 'UInt64'), self::col('label', 'LowCardinality(String)'), self::col('note', 'Nullable(String)')],
            [new IndexDefinition('ORDER BY', ['id'], false, false)],
            [],
            null,
            null,
        );

        $plan = (new DataChangePlanner())->plan(Engine::ClickHouse, $table, new DataDraft(
            [new RowUpdate(['id' => '5', 'label' => 'a', 'note' => null], ['label' => 'b'])],
            [new RowInsert(['id' => '6', 'label' => 'c'])],
            [new RowDelete(['id' => '5', 'label' => 'a', 'note' => null])],
        ));

        self::assertFalse($plan->transactional);
        self::assertTrue($plan->asynchronous);
        [$update, $delete, $insert] = $plan->statements;
        self::assertSame('ALTER TABLE `events` UPDATE `label` = {p1:String} WHERE `id` = {p2:UInt64} AND `label` = {p3:String} AND `note` IS NULL', $update->sql);
        self::assertSame(['p1' => 'b', 'p2' => '5', 'p3' => 'a'], $update->params);
        self::assertSame('SELECT count() FROM `events` WHERE `id` = {p1:UInt64} AND `label` = {p2:String} AND `note` IS NULL', $update->matchSql);
        self::assertSame(['p1' => '5', 'p2' => 'a'], $update->matchParams);
        self::assertSame('ALTER TABLE `events` DELETE WHERE `id` = {p1:UInt64} AND `label` = {p2:String} AND `note` IS NULL', $delete->sql);
        self::assertSame('INSERT INTO `events` (`id`, `label`) SELECT {p1:UInt64}, {p2:String}', $insert->sql);
        self::assertSame(['p1' => '6', 'p2' => 'c'], $insert->params);
    }
}
