<?php

declare(strict_types=1);

namespace App\Tests\Core\Schema\Change;

use App\Core\Connection\Engine;
use App\Core\Driver\TargetConnection;
use App\Core\Schema\Change\BigTableLevel;
use App\Core\Schema\Change\BigTablePolicy;
use App\Core\Schema\Change\ColumnDraft;
use App\Core\Schema\Change\DdlExecutor;
use App\Core\Schema\Change\Exception\BigTableNeedsConfirmationException;
use App\Core\Schema\Change\SchemaChangePlanner;
use App\Core\Schema\Change\SchemaChangeService;
use App\Core\Schema\Change\TableDraft;
use App\Core\Schema\ColumnDefinition;
use App\Core\Schema\SchemaIntrospector;
use App\Core\Schema\TableDefinition;
use App\Core\Schema\TableKind;
use App\Core\Schema\TablePage;
use App\Core\Schema\TablePageRequest;
use PHPUnit\Framework\TestCase;

final class SchemaChangeServiceBigTableTest extends TestCase
{
    /** @var \ArrayObject<int, string> */
    private \ArrayObject $executed;

    protected function setUp(): void
    {
        $this->executed = new \ArrayObject();
    }

    private function table(?int $rows): TableDefinition
    {
        return new TableDefinition('events', TableKind::Table, [new ColumnDefinition('id', 'integer', false, null, false, null)], [], [], null, $rows);
    }

    private function target(TableDefinition $table): TargetConnection
    {
        $introspector = new class($table) implements SchemaIntrospector {
            public function __construct(private readonly TableDefinition $table)
            {
            }

            public function listDatabases(): array
            {
                return [];
            }

            public function listSchemas(): array
            {
                return [];
            }

            public function listTables(): array
            {
                return [];
            }

            public function listTablesPage(TablePageRequest $request): TablePage
            {
                return new TablePage([], null, 0);
            }

            public function describeTable(string $name): TableDefinition
            {
                return $this->table;
            }
        };
        $ddl = new class($this->executed) implements DdlExecutor {
            /** @param \ArrayObject<int, string> $log */
            public function __construct(private readonly \ArrayObject $log)
            {
            }

            public function execute(array $statements, bool $transactional): void
            {
                foreach ($statements as $statement) {
                    $this->log->append($statement->sql);
                }
            }
        };

        return new TargetConnection(Engine::PostgreSql, $introspector, new NullRunner(), static function (): void {
        }, null, $ddl);
    }

    private function service(): SchemaChangeService
    {
        return new SchemaChangeService(new SchemaChangePlanner(), new BigTablePolicy(100, 1000));
    }

    private function addColumn(): TableDraft
    {
        return new TableDraft('events', [
            new ColumnDraft('id', 'id', 'integer', false, null, false),
            new ColumnDraft(null, 'note', 'text', true, null, false),
        ], [], []);
    }

    public function testPreviewWarnsAboveTheFirstThreshold(): void
    {
        $preview = $this->service()->preview($this->target($this->table(500)), 'events', $this->addColumn(), null);

        self::assertSame(BigTableLevel::Warning, $preview->bigTable->level);
        self::assertSame(500, $preview->bigTable->estimatedRows);
        self::assertSame(100, $preview->bigTable->warnAt);
        self::assertSame(1000, $preview->bigTable->confirmAt);
    }

    public function testPreviewAsksForTheNameAboveTheSecondThreshold(): void
    {
        $preview = $this->service()->preview($this->target($this->table(5000)), 'events', null, null);

        self::assertSame(BigTableLevel::ConfirmName, $preview->bigTable->level);
    }

    public function testUnknownEstimateGivesNoWarning(): void
    {
        $preview = $this->service()->preview($this->target($this->table(null)), 'events', $this->addColumn(), null);

        self::assertSame(BigTableLevel::None, $preview->bigTable->level);
        self::assertNull($preview->bigTable->estimatedRows);
    }

    public function testNothingToDoGivesNoWarning(): void
    {
        $same = new TableDraft('events', [new ColumnDraft('id', 'id', 'integer', false, null, false)], [], []);

        $preview = $this->service()->preview($this->target($this->table(5000)), 'events', $same, null);

        self::assertSame(BigTableLevel::None, $preview->bigTable->level);
    }

    public function testWarningLevelAppliesWithoutTheName(): void
    {
        $target = $this->target($this->table(500));

        $this->service()->apply($target, 'events', $this->addColumn(), SchemaChangePlanner::fingerprint($this->table(500)), false);

        self::assertCount(1, $this->executed);
    }

    public function testNameLevelRefusesWithoutTheName(): void
    {
        $table = $this->table(5000);

        try {
            $this->service()->apply($this->target($table), 'events', $this->addColumn(), SchemaChangePlanner::fingerprint($table), true);
            self::fail('The name check was skipped.');
        } catch (BigTableNeedsConfirmationException $e) {
            self::assertSame(5000, $e->assessment->estimatedRows);
        }
        self::assertCount(0, $this->executed);
    }

    public function testNameLevelRefusesAWrongName(): void
    {
        $table = $this->table(5000);

        $this->expectException(BigTableNeedsConfirmationException::class);
        try {
            $this->service()->apply($this->target($table), 'events', $this->addColumn(), SchemaChangePlanner::fingerprint($table), true, 'Events');
        } finally {
            self::assertCount(0, $this->executed);
        }
    }

    public function testNameLevelAppliesWithTheExactName(): void
    {
        $table = $this->table(5000);

        $this->service()->apply($this->target($table), 'events', $this->addColumn(), SchemaChangePlanner::fingerprint($table), false, 'events');

        self::assertCount(1, $this->executed);
    }

    public function testDroppingStillNeedsTheDestructiveConfirmationToo(): void
    {
        $table = $this->table(5000);

        $this->expectException(\App\Core\Schema\Change\Exception\SchemaChangeNeedsConfirmationException::class);
        $this->service()->apply($this->target($table), 'events', null, SchemaChangePlanner::fingerprint($table), false, 'events');
    }
}
