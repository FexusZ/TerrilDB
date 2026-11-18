<?php

declare(strict_types=1);

namespace App\Tests\Http\Json;

use App\Core\Schema\Change\ChangePlan;
use App\Core\Schema\Change\PlanLoss;
use App\Core\Schema\ColumnDefinition;
use App\Core\Schema\TableDefinition;
use App\Core\Schema\TableKind;
use App\Http\Json\ChangePlanPresenter;
use App\Http\Json\SchemaPresenter;
use PHPUnit\Framework\TestCase;

final class VersionedPresentationTest extends TestCase
{
    private static function table(bool $versioned): TableDefinition
    {
        return new TableDefinition('t', TableKind::Table, [new ColumnDefinition('id', 'int', false, null, false, null)], [], [], null, null, versioned: $versioned);
    }

    public function testTheTableSaysWhetherItIsVersioned(): void
    {
        self::assertTrue(SchemaPresenter::table(self::table(true))['versioned']);
        self::assertFalse(SchemaPresenter::table(self::table(false))['versioned']);
    }

    public function testThePlanListsWhatItWillLose(): void
    {
        $plan = new ChangePlan([], true, [new PlanLoss('system_history', 't'), new PlanLoss('values_removed', 'kind', ['z'])]);

        self::assertSame(
            [['code' => 'system_history', 'subject' => 't', 'values' => []], ['code' => 'values_removed', 'subject' => 'kind', 'values' => ['z']]],
            ChangePlanPresenter::plan($plan)['losses'],
        );
    }
}
