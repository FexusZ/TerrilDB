<?php

declare(strict_types=1);

namespace App\Tests\Core\Schema;

use App\Core\Schema\Change\SchemaChangePlanner;
use App\Core\Schema\ColumnDefinition;
use App\Core\Schema\Exception\InvalidSchemaException;
use App\Core\Schema\PartitionDefinition;
use App\Core\Schema\PartitioningInfo;
use App\Core\Schema\PartitionMethod;
use App\Core\Schema\TableDefinition;
use App\Core\Schema\TableKind;
use PHPUnit\Framework\TestCase;

final class PartitioningTest extends TestCase
{
    private function table(?PartitioningInfo $partitioning, ?int $rows = null): TableDefinition
    {
        return new TableDefinition(
            'events',
            TableKind::Table,
            [new ColumnDefinition('id', 'int', false, null, false, null), new ColumnDefinition('created_at', 'date', false, null, false, null)],
            [],
            [],
            null,
            $rows,
            null,
            null,
            $partitioning,
        );
    }

    private function info(int $rows, int $bytes): PartitioningInfo
    {
        return new PartitioningInfo(PartitionMethod::Range, 'created_at', null, null, [
            new PartitionDefinition('p2024', 'LESS THAN (\'2025-01-01\')', '2025-01-01', false, $rows, $bytes),
            new PartitionDefinition('pmax', 'LESS THAN (MAXVALUE)', null, true, null, null),
        ]);
    }

    public function testAPartitionedTableKeepsItsPartitioning(): void
    {
        $table = $this->table($this->info(10, 16384));

        self::assertNotNull($table->partitioning);
        self::assertSame(['p2024', 'pmax'], array_map(static fn (PartitionDefinition $p): string => $p->name, $table->partitioning->partitions));
        self::assertNull($this->table(null)->partitioning);
    }

    public function testTheFingerprintIgnoresPartitionsAndTheirMeasures(): void
    {
        $before = SchemaChangePlanner::fingerprint($this->table($this->info(10, 16384), 10));
        $after = SchemaChangePlanner::fingerprint($this->table($this->info(5000, 9999999), 5000));

        self::assertSame($before, $after);
        self::assertSame($before, SchemaChangePlanner::fingerprint($this->table(null)));
    }

    public function testAPartitioningNeedsAnExpressionAndPartitions(): void
    {
        $this->expectException(InvalidSchemaException::class);
        new PartitioningInfo(PartitionMethod::Hash, '', null, null, []);
    }

    public function testAMeasureCannotBeNegative(): void
    {
        $this->expectException(InvalidSchemaException::class);
        new PartitionDefinition('p0', null, null, false, -1, null);
    }

    public function testTheTotalsAddUpWhatTheEngineGives(): void
    {
        $info = $this->info(10, 100);

        self::assertSame(10, $info->estimatedRows());
        self::assertSame(100, $info->dataBytes());
        self::assertSame(2, $info->count());
    }
}
