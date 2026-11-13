<?php

declare(strict_types=1);

namespace App\Tests\Integration\Driver;

use App\Core\Driver\Exception\TargetConnectionFailedException;
use App\Core\Schema\Change\ColumnDraft;
use App\Core\Schema\Change\Exception\UnsupportedPartitionChangeException;
use App\Core\Schema\Change\SchemaChangePlanner;
use App\Core\Schema\Change\TableDraft;
use App\Core\Schema\PartitionMethod;
use App\Core\Schema\TableDefinition;
use App\Driver\DbalConnector;
use App\Driver\MySql\MySqlIntrospector;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Lecture des partitions sur MySQL et MariaDB, par des tables jetables créées puis supprimées (rien ne reste sur la cible).
 */
#[Group('integration')]
final class MySqlPartitionsTest extends TestCase
{
    private ?Connection $connection = null;

    /** @var list<string> */
    private array $tables = [];

    protected function tearDown(): void
    {
        foreach ($this->tables as $table) {
            try {
                $this->connection?->executeStatement('DROP TABLE IF EXISTS `'.$table.'`');
            } catch (\Throwable) {
            }
        }
        $this->connection?->close();
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function targets(): iterable
    {
        yield 'MySQL' => ['TARGET_MYSQL_URL'];
        yield 'MySQL 26' => ['TARGET_MYSQL26_URL'];
        yield 'MariaDB' => ['TARGET_MARIADB_URL'];
        yield 'MariaDB 13' => ['TARGET_MARIADB13_URL'];
    }

    private function open(string $env): MySqlIntrospector
    {
        try {
            $this->connection = (new DbalConnector())->connect(TargetDatabase::engine($env), TargetDatabase::parameters($env));
        } catch (TargetConnectionFailedException) {
            self::markTestSkipped(\sprintf('%s is unreachable (docker compose --profile targets up -d --wait).', $env));
        }

        return new MySqlIntrospector($this->connection);
    }

    private function create(string $columns, string $partitioning): string
    {
        \assert(null !== $this->connection);
        $name = $this->tables[] = 'tdb_part_'.bin2hex(random_bytes(4));
        $this->connection->executeStatement(\sprintf('CREATE TABLE `%s` (%s) ENGINE=InnoDB %s', $name, $columns, $partitioning));

        return $name;
    }

    #[DataProvider('targets')]
    public function testAPlainTableHasNoPartitioning(string $env): void
    {
        $introspector = $this->open($env);
        $name = $this->create('id INT NOT NULL PRIMARY KEY', '');

        self::assertNull($introspector->describeTable($name)->partitioning);
    }

    #[DataProvider('targets')]
    public function testReadsARangeOnADateWithItsBoundsAndTheCatchAll(string $env): void
    {
        $introspector = $this->open($env);
        $name = $this->create(
            'id INT NOT NULL, created_on DATE NOT NULL, PRIMARY KEY (id, created_on)',
            "PARTITION BY RANGE COLUMNS (created_on) (PARTITION p2023 VALUES LESS THAN ('2024-01-01'), PARTITION p2024 VALUES LESS THAN ('2025-01-01'), PARTITION pmax VALUES LESS THAN (MAXVALUE))",
        );

        $partitioning = $introspector->describeTable($name)->partitioning;

        self::assertNotNull($partitioning);
        self::assertSame(PartitionMethod::Range, $partitioning->method);
        self::assertSame('created_on', trim($partitioning->expression, '`'));
        self::assertNull($partitioning->subMethod);
        self::assertSame(['p2023', 'p2024', 'pmax'], array_map(static fn ($p): string => $p->name, $partitioning->partitions));
        self::assertSame('2024-01-01', $partitioning->partitions[0]->upperBound);
        self::assertSame('2025-01-01', $partitioning->partitions[1]->upperBound);
        self::assertFalse($partitioning->partitions[1]->catchAll);
        self::assertTrue($partitioning->partitions[2]->catchAll);
        self::assertNull($partitioning->partitions[2]->upperBound);
        self::assertNotNull($partitioning->partitions[0]->dataBytes);
    }

    #[DataProvider('targets')]
    public function testReadsARangeOnAnExpressionOfIntegers(string $env): void
    {
        $introspector = $this->open($env);
        $name = $this->create('id INT NOT NULL PRIMARY KEY', 'PARTITION BY RANGE (id) (PARTITION p0 VALUES LESS THAN (100), PARTITION p1 VALUES LESS THAN (200))');

        $partitioning = $introspector->describeTable($name)->partitioning;

        self::assertNotNull($partitioning);
        self::assertSame(PartitionMethod::Range, $partitioning->method);
        self::assertSame('100', $partitioning->partitions[0]->upperBound);
        self::assertSame('LESS THAN (100)', $partitioning->partitions[0]->bound);
    }

    #[DataProvider('targets')]
    public function testReadsAList(string $env): void
    {
        $introspector = $this->open($env);
        $name = $this->create('id INT NOT NULL, region INT NOT NULL, PRIMARY KEY (id, region)', 'PARTITION BY LIST (region) (PARTITION north VALUES IN (1, 2), PARTITION south VALUES IN (3))');

        $partitioning = $introspector->describeTable($name)->partitioning;

        self::assertNotNull($partitioning);
        self::assertSame(PartitionMethod::List, $partitioning->method);
        self::assertSame(['north', 'south'], array_map(static fn ($p): string => $p->name, $partitioning->partitions));
        self::assertSame('IN (1,2)', $partitioning->partitions[0]->bound);
        self::assertNull($partitioning->partitions[0]->upperBound);
    }

    #[DataProvider('targets')]
    public function testReadsAHashAndAKey(string $env): void
    {
        $introspector = $this->open($env);
        $hash = $this->create('id INT NOT NULL PRIMARY KEY', 'PARTITION BY HASH (id) PARTITIONS 4');
        $key = $this->create('id INT NOT NULL PRIMARY KEY', 'PARTITION BY KEY () PARTITIONS 3');

        $hashed = $introspector->describeTable($hash)->partitioning;
        $keyed = $introspector->describeTable($key)->partitioning;

        self::assertNotNull($hashed);
        self::assertSame(PartitionMethod::Hash, $hashed->method);
        self::assertSame(4, $hashed->count());
        self::assertNotNull($keyed);
        self::assertSame(PartitionMethod::Key, $keyed->method);
        self::assertSame(3, $keyed->count());
        self::assertNull($keyed->partitions[0]->upperBound);
    }

    #[DataProvider('targets')]
    public function testReadsSubpartitionsAndGroupsThemUnderTheirPartition(string $env): void
    {
        $introspector = $this->open($env);
        $name = $this->create('id INT NOT NULL PRIMARY KEY', 'PARTITION BY RANGE (id) SUBPARTITION BY HASH (id) SUBPARTITIONS 2 (PARTITION p0 VALUES LESS THAN (100), PARTITION p1 VALUES LESS THAN (MAXVALUE))');

        $partitioning = $introspector->describeTable($name)->partitioning;

        self::assertNotNull($partitioning);
        self::assertSame(2, $partitioning->count());
        self::assertSame(PartitionMethod::Hash, $partitioning->subMethod);
        self::assertNotNull($partitioning->subExpression);
        self::assertCount(2, $partitioning->partitions[0]->subpartitions);
        self::assertCount(2, $partitioning->partitions[1]->subpartitions);
    }

    #[DataProvider('targets')]
    public function testTheFingerprintDoesNotMoveWhenRowsArrive(string $env): void
    {
        $introspector = $this->open($env);
        \assert(null !== $this->connection);
        $name = $this->create('id INT NOT NULL PRIMARY KEY, label VARCHAR(20) NOT NULL', 'PARTITION BY HASH (id) PARTITIONS 4');
        $before = $introspector->describeTable($name);

        for ($i = 1; $i <= 200; ++$i) {
            $this->connection->executeStatement(\sprintf('INSERT INTO `%s` (id, label) VALUES (%d, %s)', $name, $i, $this->connection->quote('row '.$i)));
        }
        $this->connection->fetchAllAssociative(\sprintf('ANALYZE TABLE `%s`', $name));
        $after = $introspector->describeTable($name);

        self::assertSame(SchemaChangePlanner::fingerprint($before), SchemaChangePlanner::fingerprint($after));
        self::assertNotNull($after->partitioning);
        self::assertGreaterThan(0, $after->partitioning->estimatedRows());
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

    #[DataProvider('targets')]
    public function testTheEditorRefusesToDropThePartitionColumnOfARealTable(string $env): void
    {
        $introspector = $this->open($env);
        $name = $this->create('id INT NOT NULL, created_on DATE NOT NULL, PRIMARY KEY (id, created_on)', "PARTITION BY RANGE COLUMNS (created_on) (PARTITION p0 VALUES LESS THAN ('2030-01-01'))");
        $table = $introspector->describeTable($name);

        try {
            (new SchemaChangePlanner())->plan(TargetDatabase::engine($env), $table, self::draftWithout($table, 'created_on'));
            self::fail('The change should have been refused.');
        } catch (UnsupportedPartitionChangeException $e) {
            self::assertSame('partition_column', $e->reason);
            self::assertSame('created_on', $e->subject);
        }
    }
}
