<?php

declare(strict_types=1);

namespace App\Tests\Integration\Driver;

use App\Core\Connection\Engine;
use App\Core\Driver\Exception\TargetConnectionFailedException;
use App\Core\Schema\Change\ColumnDraft;
use App\Core\Schema\Change\Exception\UnsupportedPartitionChangeException;
use App\Core\Schema\Change\SchemaChangePlanner;
use App\Core\Schema\Change\TableDraft;
use App\Core\Schema\TableDefinition;
use App\Core\Schema\PartitionMethod;
use App\Driver\DbalConnector;
use App\Driver\PostgreSql\PostgreSqlIntrospector;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Lecture des partitions déclaratives de PostgreSQL, par des tables jetables créées puis supprimées (les partitions partent avec leur parente).
 */
#[Group('integration')]
final class PostgreSqlPartitionsTest extends TestCase
{
    private ?Connection $connection = null;

    /** @var list<string> */
    private array $tables = [];

    protected function tearDown(): void
    {
        foreach ($this->tables as $table) {
            try {
                $this->connection?->executeStatement('DROP TABLE IF EXISTS "'.$table.'" CASCADE');
            } catch (\Throwable) {
            }
        }
        $this->connection?->close();
    }

    private function open(): PostgreSqlIntrospector
    {
        try {
            $this->connection = (new DbalConnector())->connect(Engine::PostgreSql, TargetDatabase::parameters('TARGET_POSTGRES_URL'));
        } catch (TargetConnectionFailedException) {
            self::markTestSkipped('TARGET_POSTGRES_URL is unreachable (docker compose --profile targets up -d --wait).');
        }

        return new PostgreSqlIntrospector($this->connection);
    }

    /**
     * @param list<string> $children corps SQL de chaque partition, avec « %s » pour son nom
     */
    private function create(string $columns, string $partitionBy, array $children): string
    {
        \assert(null !== $this->connection);
        $name = $this->tables[] = 'tdb_part_'.bin2hex(random_bytes(4));
        $this->connection->executeStatement(\sprintf('CREATE TABLE "%s" (%s) PARTITION BY %s', $name, $columns, $partitionBy));
        foreach ($children as $i => $child) {
            $this->connection->executeStatement(\sprintf('CREATE TABLE "%s_p%d" PARTITION OF "%s" %s', $name, $i, $name, $child));
        }

        return $name;
    }

    public function testAPlainTableHasNoPartitioning(): void
    {
        $introspector = $this->open();
        \assert(null !== $this->connection);
        $name = $this->tables[] = 'tdb_part_'.bin2hex(random_bytes(4));
        $this->connection->executeStatement(\sprintf('CREATE TABLE "%s" (id integer PRIMARY KEY)', $name));

        self::assertNull($introspector->describeTable($name)->partitioning);
    }

    public function testReadsARangeOnADateWithItsBoundsAndTheDefaultPartition(): void
    {
        $introspector = $this->open();
        $name = $this->create('id integer NOT NULL, created_on date NOT NULL', 'RANGE (created_on)', [
            "FOR VALUES FROM ('2023-01-01') TO ('2024-01-01')",
            "FOR VALUES FROM ('2024-01-01') TO ('2025-01-01')",
            'DEFAULT',
        ]);

        $partitioning = $introspector->describeTable($name)->partitioning;

        self::assertNotNull($partitioning);
        self::assertSame(PartitionMethod::Range, $partitioning->method);
        self::assertSame('created_on', $partitioning->expression);
        self::assertSame([$name.'_p0', $name.'_p1', $name.'_p2'], array_map(static fn ($p): string => $p->name, $partitioning->partitions));
        self::assertSame("FROM ('2023-01-01') TO ('2024-01-01')", $partitioning->partitions[0]->bound);
        self::assertSame('2024-01-01', $partitioning->partitions[0]->upperBound);
        self::assertSame('2025-01-01', $partitioning->partitions[1]->upperBound);
        self::assertTrue($partitioning->partitions[2]->catchAll);
        self::assertNull($partitioning->partitions[2]->upperBound);
        self::assertNotNull($partitioning->partitions[0]->dataBytes);
    }

    public function testReadsARangeOnAnExpression(): void
    {
        $introspector = $this->open();
        $name = $this->create('id integer NOT NULL, label text', 'RANGE ((id * 2))', ['FOR VALUES FROM (0) TO (100)']);

        $partitioning = $introspector->describeTable($name)->partitioning;

        self::assertNotNull($partitioning);
        self::assertSame('(id * 2)', $partitioning->expression);
        self::assertSame('100', $partitioning->partitions[0]->upperBound);
    }

    public function testReadsAList(): void
    {
        $introspector = $this->open();
        $name = $this->create('id integer NOT NULL, region text NOT NULL', 'LIST (region)', ["FOR VALUES IN ('north', 'east')", "FOR VALUES IN ('south')"]);

        $partitioning = $introspector->describeTable($name)->partitioning;

        self::assertNotNull($partitioning);
        self::assertSame(PartitionMethod::List, $partitioning->method);
        self::assertSame("IN ('north', 'east')", $partitioning->partitions[0]->bound);
        self::assertNull($partitioning->partitions[0]->upperBound);
        self::assertFalse($partitioning->partitions[0]->catchAll);
    }

    public function testReadsAHash(): void
    {
        $introspector = $this->open();
        $name = $this->create('id integer NOT NULL', 'HASH (id)', [
            'FOR VALUES WITH (MODULUS 2, REMAINDER 0)',
            'FOR VALUES WITH (MODULUS 2, REMAINDER 1)',
        ]);

        $partitioning = $introspector->describeTable($name)->partitioning;

        self::assertNotNull($partitioning);
        self::assertSame(PartitionMethod::Hash, $partitioning->method);
        self::assertSame(2, $partitioning->count());
        self::assertSame('WITH (modulus 2, remainder 0)', $partitioning->partitions[0]->bound);
    }

    public function testTheFingerprintDoesNotMoveWhenRowsArrive(): void
    {
        $introspector = $this->open();
        \assert(null !== $this->connection);
        $name = $this->create('id integer NOT NULL, label text', 'HASH (id)', ['FOR VALUES WITH (MODULUS 2, REMAINDER 0)', 'FOR VALUES WITH (MODULUS 2, REMAINDER 1)']);
        $before = $introspector->describeTable($name);

        $this->connection->executeStatement(\sprintf('INSERT INTO "%s" SELECT g, \'row\' FROM generate_series(1, 500) g', $name));
        $this->connection->executeStatement(\sprintf('ANALYZE "%s"', $name));
        $after = $introspector->describeTable($name);

        self::assertSame(SchemaChangePlanner::fingerprint($before), SchemaChangePlanner::fingerprint($after));
        self::assertNotNull($after->partitioning);
        self::assertSame(500, $after->partitioning->estimatedRows());
    }

    private static function draftWithout(TableDefinition $table, string $dropped): TableDraft
    {
        $columns = [];
        foreach ($table->columns as $column) {
            if ($column->name !== $dropped) {
                $columns[] = new ColumnDraft($column->name, $column->name, $column->type, $column->nullable, null, false);
            }
        }

        return new TableDraft($table->name, $columns, [], []);
    }

    public function testTheEditorRefusesToDropThePartitionColumnOfARealTable(): void
    {
        $introspector = $this->open();
        $name = $this->create('id integer NOT NULL, created_on date NOT NULL', 'RANGE (created_on)', ["FOR VALUES FROM ('2020-01-01') TO ('2030-01-01')"]);
        $table = $introspector->describeTable($name);

        $this->expectException(UnsupportedPartitionChangeException::class);
        (new SchemaChangePlanner())->plan(Engine::PostgreSql, $table, self::draftWithout($table, 'created_on'));
    }
}
