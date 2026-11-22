<?php

declare(strict_types=1);

namespace App\Tests\Core\Diagnostics\Vitess;

use App\Core\Diagnostics\DiagnosticStatus;
use App\Core\Diagnostics\ServerVariable;
use App\Core\Diagnostics\VariableReport;
use App\Core\Diagnostics\Vitess\TabletComparer;
use App\Core\Diagnostics\Vitess\TabletSnapshot;
use App\Core\Diagnostics\Vitess\VitessTablet;
use PHPUnit\Framework\TestCase;

/**
 * Tablet contre tablet : une variable ne figure dans l'écart que si deux tablets lus la rendent différente ; absente de la liste des
 * variables modifiées d'un tablet, elle est à son défaut sur celui-là. Une variable masquée n'est jamais montrée, seulement comptée.
 */
final class TabletComparerTest extends TestCase
{
    private function tablet(string $alias, string $type = 'REPLICA'): VitessTablet
    {
        return new VitessTablet($alias, 'zone1', 'shop', '0', $type, true);
    }

    private function variable(string $name, string $value): ServerVariable
    {
        return new ServerVariable($name, $value, '1', true, null, null, 'global');
    }

    /**
     * @param list<ServerVariable> $variables
     */
    private function snapshot(string $alias, array $variables, string $type = 'REPLICA'): TabletSnapshot
    {
        return new TabletSnapshot($this->tablet($alias, $type), null, new VariableReport(DiagnosticStatus::available(), $variables, false), null);
    }

    public function testAVariableThatDiffersBetweenTabletsIsListedWithEachValue(): void
    {
        $comparison = (new TabletComparer())->compare([
            $this->snapshot('zone1-100', [$this->variable('max_connections', '500'), $this->variable('same', '1')], 'PRIMARY'),
            $this->snapshot('zone1-101', [$this->variable('max_connections', '300'), $this->variable('same', '1')]),
        ]);

        self::assertCount(1, $comparison->differences);
        self::assertSame('max_connections', $comparison->differences[0]->name);
        self::assertSame(['zone1-100' => '500', 'zone1-101' => '300'], $comparison->differences[0]->values);
    }

    public function testAVariableChangedOnOneTabletOnlyIsAtItsDefaultOnTheOthers(): void
    {
        $comparison = (new TabletComparer())->compare([
            $this->snapshot('zone1-100', [$this->variable('sort_buffer_size', '4194304')], 'PRIMARY'),
            $this->snapshot('zone1-101', []),
        ]);

        self::assertSame(['zone1-100' => '4194304', 'zone1-101' => null], $comparison->differences[0]->values);
    }

    public function testASecretIsCountedNeverShown(): void
    {
        $comparison = (new TabletComparer())->compare([
            $this->snapshot('zone1-100', [$this->variable('replication_password', 'a')], 'PRIMARY'),
            $this->snapshot('zone1-101', [$this->variable('replication_password', 'b')]),
        ]);

        self::assertSame([], $comparison->differences);
        self::assertSame(1, $comparison->masked);
    }

    public function testATabletThatCouldNotBeReadIsKeptApartAndNotCountedAsDefault(): void
    {
        $comparison = (new TabletComparer())->compare([
            $this->snapshot('zone1-100', [$this->variable('max_connections', '500')], 'PRIMARY'),
            new TabletSnapshot($this->tablet('zone1-101'), null, null, 'unreachable'),
            $this->snapshot('zone1-102', [$this->variable('max_connections', '500')]),
        ]);

        self::assertSame([], $comparison->differences, 'les deux tablets lus sont d\'accord');
        self::assertSame('unreachable', $comparison->snapshots[1]->failure);
    }

    public function testNoDifferenceWhenEveryTabletAgrees(): void
    {
        $comparison = (new TabletComparer())->compare([
            $this->snapshot('zone1-100', [$this->variable('a', '1')], 'PRIMARY'),
            $this->snapshot('zone1-101', [$this->variable('a', '1')]),
        ]);

        self::assertSame([], $comparison->differences);
    }

    public function testAnUnavailableVariableSourceIsNotTakenForDefaults(): void
    {
        $unavailable = new TabletSnapshot($this->tablet('zone1-101'), null, new VariableReport(DiagnosticStatus::unsupported('performance_schema_off'), [], false), null);
        $comparison = (new TabletComparer())->compare([
            $this->snapshot('zone1-100', [$this->variable('a', '1')], 'PRIMARY'),
            $unavailable,
        ]);

        self::assertSame([], $comparison->differences, 'une source illisible n\'est pas « au défaut »');
    }

    public function testItKeepsOneKeyspacePutsThePrimaryFirstAndCapsTheCount(): void
    {
        $listing = new \App\Core\Diagnostics\Vitess\TabletListing([
            new VitessTablet('zone1-103', 'zone1', 'shop', '0', 'REPLICA', true),
            new VitessTablet('zone1-200', 'zone1', 'other', '0', 'PRIMARY', true),
            new VitessTablet('zone1-101', 'zone1', 'shop', '0', 'PRIMARY', true),
            new VitessTablet('zone1-102', 'zone1', 'shop', '0', 'RDONLY', true),
        ]);

        [$picked, $truncated] = TabletComparer::select($listing, 'shop', 3);
        self::assertSame(['zone1-101', 'zone1-102', 'zone1-103'], array_map(static fn (VitessTablet $t): string => $t->alias, $picked));
        self::assertFalse($truncated);

        [$picked, $truncated] = TabletComparer::select($listing, 'shop', 2);
        self::assertCount(2, $picked);
        self::assertTrue($truncated);

        [$picked] = TabletComparer::select($listing, null, 10);
        self::assertCount(4, $picked, 'sans keyspace demandé, tous');
    }
}
