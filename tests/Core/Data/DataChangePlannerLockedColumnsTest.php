<?php

declare(strict_types=1);

namespace App\Tests\Core\Data;

use App\Core\Connection\Engine;
use App\Core\Data\DataChangePlanner;
use App\Core\Data\DataDraft;
use App\Core\Data\Exception\InvalidDataChangeException;
use App\Core\Data\RowInsert;
use App\Core\Data\RowUpdate;
use App\Core\Schema\ColumnDefinition;
use App\Core\Schema\IndexDefinition;
use App\Core\Schema\LockReason;
use App\Core\Schema\TableDefinition;
use App\Core\Schema\TableKind;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Le moteur calcule une colonne générée (et les colonnes de période d'une table versionnée) : une valeur qu'on lui en donne est
 * refusée d'avance, jamais envoyée. Les colonnes cachées d'une table versionnée ne sont pas dans le modèle : elles ne partent donc jamais.
 */
final class DataChangePlannerLockedColumnsTest extends TestCase
{
    private static function table(LockReason $lock): TableDefinition
    {
        return new TableDefinition(
            'orders',
            TableKind::Table,
            [
                new ColumnDefinition('id', 'int', false, null, false, null),
                new ColumnDefinition('qty', 'int', true, null, false, null),
                new ColumnDefinition('computed', 'int', true, null, false, null, lock: $lock),
            ],
            [new IndexDefinition('PRIMARY', ['id'], true, true)],
            [],
            null,
            10,
        );
    }

    /**
     * @return iterable<string, array{LockReason}>
     */
    public static function writtenByTheEngine(): iterable
    {
        yield 'generated' => [LockReason::Generated];
        yield 'system period' => [LockReason::SystemPeriod];
    }

    #[DataProvider('writtenByTheEngine')]
    public function testChangingTheValueOfSuchAColumnIsRefused(LockReason $lock): void
    {
        $this->expectException(InvalidDataChangeException::class);
        (new DataChangePlanner())->plan(Engine::MariaDb, self::table($lock), new DataDraft(
            [new RowUpdate(['id' => 1, 'qty' => 1, 'computed' => 2], ['computed' => 5])],
            [],
            [],
        ));
    }

    #[DataProvider('writtenByTheEngine')]
    public function testInsertingAValueInSuchAColumnIsRefused(LockReason $lock): void
    {
        $this->expectException(InvalidDataChangeException::class);
        (new DataChangePlanner())->plan(Engine::MariaDb, self::table($lock), new DataDraft([], [new RowInsert(['id' => 1, 'computed' => 5])], []));
    }

    #[DataProvider('writtenByTheEngine')]
    public function testAnUnchangedValueOfSuchAColumnIsNotAChange(LockReason $lock): void
    {
        $plan = (new DataChangePlanner())->plan(Engine::MariaDb, self::table($lock), new DataDraft(
            [new RowUpdate(['id' => 1, 'qty' => 1, 'computed' => 2], ['computed' => 2, 'qty' => 9])],
            [],
            [],
        ));

        self::assertCount(1, $plan->statements);
        self::assertStringNotContainsString('computed', $plan->statements[0]->display);
    }

    public function testOrdinaryColumnsAreUnaffected(): void
    {
        $plan = (new DataChangePlanner())->plan(Engine::MariaDb, self::table(LockReason::Generated), new DataDraft([], [new RowInsert(['id' => 1, 'qty' => 3])], []));

        self::assertCount(1, $plan->statements);
    }
}
