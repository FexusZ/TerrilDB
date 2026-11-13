<?php

declare(strict_types=1);

namespace App\Tests\Http\Json;

use App\Core\Schema\ColumnDefinition;
use App\Core\Schema\PartitionDefinition;
use App\Core\Schema\PartitioningInfo;
use App\Core\Schema\PartitionMethod;
use App\Core\Schema\TableDefinition;
use App\Core\Schema\TableKind;
use App\Http\Json\SchemaPresenter;
use PHPUnit\Framework\TestCase;

final class SchemaPresenterTest extends TestCase
{
    private function table(?PartitioningInfo $partitioning): TableDefinition
    {
        return new TableDefinition('events', TableKind::Table, [new ColumnDefinition('id', 'int', false, null, false, null)], [], [], null, null, null, null, $partitioning);
    }

    public function testATableWithoutPartitionsSaysNull(): void
    {
        self::assertNull(SchemaPresenter::table($this->table(null))['partitioning']);
    }

    public function testAPartitionedTableExposesItsPartitions(): void
    {
        $info = new PartitioningInfo(PartitionMethod::Range, 'id', PartitionMethod::Hash, 'id', [
            new PartitionDefinition('p0', 'LESS THAN (100)', '100', false, 12, 16384, [new PartitionDefinition('p0sp0', null, null, false, 12, 16384)]),
            new PartitionDefinition('pmax', 'LESS THAN (MAXVALUE)', null, true, null, null),
        ]);

        $json = SchemaPresenter::table($this->table($info))['partitioning'];

        self::assertSame([
            'method' => 'range',
            'expression' => 'id',
            'subMethod' => 'hash',
            'subExpression' => 'id',
            'estimatedRows' => 12,
            'dataBytes' => 16384,
            'partitions' => [
                ['name' => 'p0', 'bound' => 'LESS THAN (100)', 'catchAll' => false, 'estimatedRows' => 12, 'dataBytes' => 16384, 'subpartitions' => [['name' => 'p0sp0', 'estimatedRows' => 12, 'dataBytes' => 16384]]],
                ['name' => 'pmax', 'bound' => 'LESS THAN (MAXVALUE)', 'catchAll' => true, 'estimatedRows' => null, 'dataBytes' => null, 'subpartitions' => []],
            ],
        ], $json);
    }
}
