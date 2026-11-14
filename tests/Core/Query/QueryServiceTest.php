<?php

declare(strict_types=1);

namespace App\Tests\Core\Query;

use App\Core\Connection\Engine;
use App\Core\Driver\TargetConnection;
use App\Core\Guard\GuardReason;
use App\Core\Guard\StatementGuard;
use App\Core\Guard\StatementKind;
use App\Core\Query\Exception\QueryNeedsConfirmationException;
use App\Core\Query\Exception\QueryRefusedException;
use App\Core\Query\QueryMode;
use App\Core\Query\QueryOptions;
use App\Core\Query\QueryResult;
use App\Core\Query\QueryRunner;
use App\Core\Query\QueryService;
use App\Core\Query\WritePermissions;
use App\Core\Schema\SchemaIntrospector;
use App\Core\Schema\TableDefinition;
use PHPUnit\Framework\TestCase;

final class QueryServiceTest extends TestCase
{
    private RecordingRunner $runner;

    protected function setUp(): void
    {
        $this->runner = new RecordingRunner();
    }

    private function target(Engine $engine = Engine::PostgreSql): TargetConnection
    {
        $introspector = new class implements SchemaIntrospector {
            public function listDatabases(): array
            {
                return [];
            }

            public function listTablesPage(\App\Core\Schema\TablePageRequest $request): \App\Core\Schema\TablePage
            {
                return new \App\Core\Schema\TablePage([], null, 0);
            }

            public function indexUsage(): \App\Core\Schema\IndexUsageReport
            {
                return \App\Core\Schema\IndexUsageReport::unavailable('not_supported');
            }

            public function listSchemas(): array
            {
                return [];
            }

            public function listTables(): array
            {
                return [];
            }

            public function describeTable(string $name): TableDefinition
            {
                throw new \LogicException('Not used in this test.');
            }
        };

        return new TargetConnection($engine, $introspector, $this->runner, static function (): void {
        });
    }

    private function service(): QueryService
    {
        return new QueryService(new StatementGuard());
    }

    public function testTheWriteRightsOfTheOptionsAreEnforcedBeforeRunning(): void
    {
        $options = new QueryOptions(QueryMode::ReadWrite, writes: new WritePermissions(data: true));

        try {
            $this->service()->execute($this->target(), 'create table t (a int)', $options);
            self::fail('Expected a refusal.');
        } catch (QueryRefusedException $refused) {
            self::assertSame(GuardReason::WriteNotPermitted, $refused->reason);
        }
        self::assertSame([], $this->runner->calls);
    }

    public function testAnAllowedStatementIsRunWithItsKindAndOptions(): void
    {
        $options = new QueryOptions(QueryMode::ReadOnly, 5_000, 50);

        $result = $this->service()->execute($this->target(), 'select 1', $options);

        self::assertSame([[1]], $result->rows);
        self::assertCount(1, $this->runner->calls);
        self::assertSame('select 1', $this->runner->calls[0][0]);
        self::assertSame(StatementKind::Read, $this->runner->calls[0][1]);
        self::assertSame($options, $this->runner->calls[0][2]);
    }

    public function testARefusedStatementNeverReachesTheRunner(): void
    {
        try {
            $this->service()->execute($this->target(), 'drop table t', new QueryOptions(QueryMode::ReadOnly));
            self::fail('Expected a refusal.');
        } catch (QueryRefusedException $e) {
            self::assertSame(GuardReason::NotReadOnly, $e->reason);
        }
        self::assertSame([], $this->runner->calls);
    }

    public function testTheModeComesFromTheOptions(): void
    {
        $this->service()->execute($this->target(), 'insert into t values (1)', new QueryOptions(QueryMode::ReadWrite));

        self::assertSame(StatementKind::Write, $this->runner->calls[0][1]);
    }

    public function testTheEngineDecidesTheDialect(): void
    {
        $this->expectException(QueryRefusedException::class);

        // Dans le dialecte MySQL, « --1 » n'est pas un commentaire : deux instructions.
        $this->service()->execute($this->target(Engine::MySql), 'select 1--1; drop table t', new QueryOptions());
    }

    public function testADestructiveStatementWaitsForConfirmation(): void
    {
        try {
            $this->service()->execute($this->target(), 'delete from t', new QueryOptions(QueryMode::ReadWrite));
            self::fail('Expected a confirmation request.');
        } catch (QueryNeedsConfirmationException $e) {
            self::assertSame(GuardReason::DestructiveWithoutWhere, $e->reason);
        }
        self::assertSame([], $this->runner->calls);
    }

    public function testAConfirmedStatementRuns(): void
    {
        $this->service()->execute($this->target(), 'delete from t', new QueryOptions(QueryMode::ReadWrite), confirmed: true);

        self::assertCount(1, $this->runner->calls);
        self::assertSame(StatementKind::Write, $this->runner->calls[0][1]);
    }

    public function testAConfirmationNeverLiftsARefusal(): void
    {
        foreach (['select 1; select 2', "select * from t into outfile '/tmp/x'", 'set transaction read write'] as $sql) {
            try {
                $this->service()->execute($this->target(), $sql, new QueryOptions(QueryMode::ReadWrite), confirmed: true);
                self::fail('Expected a refusal for: '.$sql);
            } catch (QueryRefusedException) {
            }
        }
        self::assertSame([], $this->runner->calls);
    }
}

final class RecordingRunner implements QueryRunner
{
    /** @var list<array{string, StatementKind, QueryOptions}> */
    public array $calls = [];

    public function run(string $sql, StatementKind $kind, QueryOptions $options): QueryResult
    {
        $this->calls[] = [$sql, $kind, $options];

        return QueryResult::rows(['a'], [[1]], false, 3);
    }
}
