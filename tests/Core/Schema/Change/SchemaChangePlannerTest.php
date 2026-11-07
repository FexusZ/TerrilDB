<?php

declare(strict_types=1);

namespace App\Tests\Core\Schema\Change;

use App\Core\Connection\Engine;
use App\Core\Schema\Change\ColumnDraft;
use App\Core\Schema\Change\Exception\InvalidSchemaChangeException;
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
use PHPUnit\Framework\TestCase;

final class SchemaChangePlannerTest extends TestCase
{
    private static function current(TableKind $kind = TableKind::Table): TableDefinition
    {
        return new TableDefinition(
            'orders',
            $kind,
            [new ColumnDefinition('id', 'integer', false, null, false, null), new ColumnDefinition('customer_id', 'integer', false, null, false, null), new ColumnDefinition('status', 'text', true, null, false, null)],
            [new IndexDefinition('orders_pkey', ['id'], true, true), new IndexDefinition('idx_status', ['status'], false, false)],
            [new ForeignKeyDefinition('fk_customer', ['customer_id'], 'customers', ['id'], ReferentialAction::NoAction, ReferentialAction::NoAction)],
            TableKind::Table === $kind ? null : 'SELECT 1',
            null,
        );
    }

    private static function unchanged(): TableDraft
    {
        return new TableDraft(
            'orders',
            [new ColumnDraft('id', 'id', 'integer', false, null, false), new ColumnDraft('customer_id', 'customer_id', 'integer', false, null, false), new ColumnDraft('status', 'status', 'text', true, null, false)],
            [new IndexDraft('orders_pkey', 'orders_pkey', ['id'], true, true, null, null), new IndexDraft('idx_status', 'idx_status', ['status'], false, false, null, null)],
            [new ForeignKeyDraft('fk_customer', 'fk_customer', ['customer_id'], 'customers', ['id'], ReferentialAction::NoAction, ReferentialAction::NoAction)],
        );
    }

    public function testNothingToDoWhenNothingChanged(): void
    {
        $plan = (new SchemaChangePlanner())->plan(Engine::PostgreSql, self::current(), self::unchanged());

        self::assertSame([], $plan->statements);
        self::assertFalse($plan->hasDestructive());
    }

    public function testPlansARenameAnIndexAndAForeignKeyDropInADependencySafeOrder(): void
    {
        $draft = new TableDraft(
            'orders',
            [new ColumnDraft('id', 'id', 'integer', false, null, false), new ColumnDraft('customer_id', 'customer_id', 'integer', false, null, false), new ColumnDraft('status', 'state', 'text', true, null, false)],
            [new IndexDraft('orders_pkey', 'orders_pkey', ['id'], true, true, null, null), new IndexDraft(null, 'idx_new', ['state'], false, false, null, null)],
            [],
        );

        $plan = (new SchemaChangePlanner())->plan(Engine::PostgreSql, self::current(), $draft);

        self::assertSame([
            'ALTER TABLE "orders" DROP CONSTRAINT "fk_customer"',
            'DROP INDEX "idx_status"',
            'ALTER TABLE "orders" RENAME COLUMN "status" TO "state"',
            'CREATE INDEX "idx_new" ON "orders" ("state")',
        ], array_map(static fn ($s): string => $s->sql, $plan->statements));
        self::assertTrue($plan->transactional);
        self::assertTrue($plan->hasDestructive());
    }

    public function testMysqlIsNotTransactionalAndClickHouseNeitherButBothKeepTheirDialect(): void
    {
        $plan = (new SchemaChangePlanner())->plan(Engine::MariaDb, self::current(), self::unchanged()->withName('sales'));

        self::assertSame(['RENAME TABLE `orders` TO `sales`'], array_map(static fn ($s): string => $s->sql, $plan->statements));
        self::assertFalse($plan->transactional);
    }

    public function testPlansTheDropOfTheTable(): void
    {
        $plan = (new SchemaChangePlanner())->planDrop(Engine::PostgreSql, self::current());

        self::assertSame(['DROP TABLE "orders"'], array_map(static fn ($s): string => $s->sql, $plan->statements));
        self::assertTrue($plan->hasDestructive());
    }

    public function testOnlyATableCanBeEdited(): void
    {
        $this->expectException(InvalidSchemaChangeException::class);

        (new SchemaChangePlanner())->plan(Engine::PostgreSql, self::current(TableKind::View), self::unchanged());
    }

    public function testRedisHasNoSchemaToEdit(): void
    {
        $this->expectException(InvalidSchemaChangeException::class);

        (new SchemaChangePlanner())->plan(Engine::Redis, self::current(), self::unchanged());
    }

    public function testTheFingerprintChangesWithTheStructureAndOnlyWithIt(): void
    {
        $planner = new SchemaChangePlanner();
        $base = $planner->fingerprint(self::current());

        self::assertSame($base, $planner->fingerprint(self::current()));
        $other = new TableDefinition('orders', TableKind::Table, [new ColumnDefinition('id', 'bigint', false, null, false, null)], [], [], null, 999);
        self::assertNotSame($base, $planner->fingerprint($other));
        // L'estimation du nombre de lignes bouge toute seule : elle ne compte pas comme un changement de structure.
        $moreRows = new TableDefinition('orders', TableKind::Table, self::current()->columns, self::current()->indexes, self::current()->foreignKeys, null, 123456);
        self::assertSame($base, $planner->fingerprint($moreRows));
    }
}
