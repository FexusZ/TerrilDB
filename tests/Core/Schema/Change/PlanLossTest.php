<?php

declare(strict_types=1);

namespace App\Tests\Core\Schema\Change;

use App\Core\Connection\Engine;
use App\Core\Schema\Change\ColumnDraft;
use App\Core\Schema\Change\EnumMembers;
use App\Core\Schema\Change\IndexDraft;
use App\Core\Schema\Change\PlanLoss;
use App\Core\Schema\Change\SchemaChangePlanner;
use App\Core\Schema\Change\TableDraft;
use App\Core\Schema\ColumnDefinition;
use App\Core\Schema\IndexDefinition;
use App\Core\Schema\TableDefinition;
use App\Core\Schema\TableKind;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Ce qu'une modification fait perdre au-delà du SQL : l'historique d'une table versionnée qu'on supprime, les valeurs retirées d'une
 * liste ENUM / SET (les lignes qui les portent ne passent plus). L'aperçu les dit, la confirmation les liste.
 */
final class PlanLossTest extends TestCase
{
    private static function table(bool $versioned, string $kindType = "enum('x','y','z')"): TableDefinition
    {
        return new TableDefinition(
            'orders',
            TableKind::Table,
            [new ColumnDefinition('id', 'int(11)', false, null, false, null), new ColumnDefinition('kind', $kindType, false, 'x', false, null)],
            [new IndexDefinition('PRIMARY', ['id'], true, true)],
            [],
            null,
            null,
            versioned: $versioned,
        );
    }

    private static function draft(string $kindType): TableDraft
    {
        return new TableDraft(
            'orders',
            [new ColumnDraft('id', 'id', 'int(11)', false, null, false), new ColumnDraft('kind', 'kind', $kindType, false, 'x', false)],
            [new IndexDraft('PRIMARY', 'PRIMARY', ['id'], true, true, null, null)],
            [],
        );
    }

    public function testDroppingAVersionedTableLosesItsHistory(): void
    {
        $plan = (new SchemaChangePlanner())->planDrop(Engine::MariaDb, self::table(true));

        self::assertEquals([new PlanLoss('system_history', 'orders')], $plan->losses);
        self::assertTrue($plan->hasDestructive());
    }

    public function testDroppingAnOrdinaryTableAnnouncesNoSpecialLoss(): void
    {
        self::assertSame([], (new SchemaChangePlanner())->planDrop(Engine::MariaDb, self::table(false))->losses);
    }

    #[DataProvider('mySqlFamily')]
    public function testRemovingAnEnumValueIsAnnounced(Engine $engine): void
    {
        $plan = (new SchemaChangePlanner())->plan($engine, self::table(false), self::draft("enum('x','y')"));

        self::assertEquals([new PlanLoss('values_removed', 'kind', ['z'])], $plan->losses);
        self::assertTrue($plan->hasDestructive());
    }

    /**
     * @return iterable<string, array{Engine}>
     */
    public static function mySqlFamily(): iterable
    {
        yield 'MySQL' => [Engine::MySql];
        yield 'MariaDB' => [Engine::MariaDb];
    }

    public function testRemovingASetValueIsAnnounced(): void
    {
        $plan = (new SchemaChangePlanner())->plan(Engine::MySql, self::table(false, "set('p','q','r')"), self::draft("set('p')"));

        self::assertEquals([new PlanLoss('values_removed', 'kind', ['q', 'r'])], $plan->losses);
    }

    public function testAppendingOrReorderingValuesLosesNothing(): void
    {
        $planner = new SchemaChangePlanner();

        self::assertSame([], $planner->plan(Engine::MySql, self::table(false), self::draft("enum('x','y','z','w')"))->losses);
        self::assertSame([], $planner->plan(Engine::MySql, self::table(false), self::draft("enum('z','y','x')"))->losses);
    }

    public function testAQuoteInsideAValueIsReadAsOneValue(): void
    {
        self::assertSame(["it's", 'b,c'], EnumMembers::of("enum('it''s','b,c')"));
        self::assertSame(['a'], EnumMembers::of("SET('a')"));
        self::assertNull(EnumMembers::of('varchar(20)'));
        self::assertNull(EnumMembers::of("enum('a'"));
    }
}
