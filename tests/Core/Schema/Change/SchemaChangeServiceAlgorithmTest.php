<?php

declare(strict_types=1);

namespace App\Tests\Core\Schema\Change;

use App\Core\Connection\Engine;
use App\Core\Driver\ServerVersion;
use App\Core\Driver\TargetConnection;
use App\Core\Schema\Change\Algorithm\AlterAlgorithm;
use App\Core\Schema\Change\ColumnDraft;
use App\Core\Schema\Change\DdlExecutor;
use App\Core\Schema\Change\SchemaChangeService;
use App\Core\Schema\Change\TableDraft;
use App\Core\Schema\ColumnDefinition;
use App\Core\Schema\SchemaIntrospector;
use App\Core\Schema\TableDefinition;
use App\Core\Schema\TableKind;
use App\Core\Schema\TablePage;
use App\Core\Schema\TablePageRequest;
use PHPUnit\Framework\TestCase;

/**
 * L'aperçu porte l'algorithme probable de chaque instruction (table de règles, rien n'est exécuté) : un avis par instruction.
 */
final class SchemaChangeServiceAlgorithmTest extends TestCase
{
    /** @var \ArrayObject<int, string> */
    private \ArrayObject $executed;

    protected function setUp(): void
    {
        $this->executed = new \ArrayObject();
    }

    private function table(): TableDefinition
    {
        return new TableDefinition('events', TableKind::Table, [new ColumnDefinition('id', 'int', false, null, false, null), new ColumnDefinition('n', 'int', true, null, false, null)], [], [], null, 500_000, 40_000_000, 10_000_000, 'InnoDB', true);
    }

    private function target(TableDefinition $table, Engine $engine = Engine::MySql, string $version = '8.4.13'): TargetConnection
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

            public function indexUsage(): \App\Core\Schema\IndexUsageReport
            {
                return \App\Core\Schema\IndexUsageReport::unavailable('not_supported');
            }

            public function autoCounters(): array
            {
                return [];
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

        return new TargetConnection($engine, $introspector, new NullRunner(), static function (): void {
        }, null, $ddl, null, null, null, ServerVersion::parse($version));
    }

    private function draft(string $type, bool $addColumn = false): TableDraft
    {
        $columns = [new ColumnDraft('id', 'id', 'int', false, null, false), new ColumnDraft('n', 'n', $type, true, null, false)];
        if ($addColumn) {
            $columns[] = new ColumnDraft(null, 'note', 'text', true, null, false);
        }

        return new TableDraft('events', $columns, [], []);
    }

    public function testThePreviewGivesOneAdvicePerStatementAndExecutesNothing(): void
    {
        $preview = (new SchemaChangeService())->preview($this->target($this->table()), 'events', $this->draft('bigint', true), null);

        self::assertCount(2, $preview->plan->statements);
        self::assertSame([AlterAlgorithm::Copy, AlterAlgorithm::Instant], array_map(static fn ($a): AlterAlgorithm => $a->algorithm, $preview->algorithms), 'the plan modifies the type, then adds the column at the end');
        self::assertCount(0, $this->executed, 'no ALTER is ever tried by the preview');
    }

    public function testACopyCarriesTheSizeOfTheTable(): void
    {
        $preview = (new SchemaChangeService())->preview($this->target($this->table()), 'events', $this->draft('bigint'), null);

        self::assertSame(AlterAlgorithm::Copy, $preview->algorithms[0]->algorithm);
        self::assertSame(50_000_000, $preview->algorithms[0]->estimatedExtraBytes);
    }

    public function testNothingToDoGivesNoAdvice(): void
    {
        $preview = (new SchemaChangeService())->preview($this->target($this->table()), 'events', $this->draft('int'), null);

        self::assertSame([], $preview->algorithms);
    }

    public function testDroppingATableIsNotAnAlter(): void
    {
        $preview = (new SchemaChangeService())->preview($this->target($this->table()), 'events', null, null);

        self::assertSame('not_an_alter', $preview->algorithms[0]->reason);
    }

    public function testAnUnverifiedServerVersionIsNeverClaimed(): void
    {
        $preview = (new SchemaChangeService())->preview($this->target($this->table(), Engine::MySql, '8.0.40'), 'events', $this->draft('int', true), null);

        self::assertSame('unverified_version', $preview->algorithms[0]->reason);
    }
}
