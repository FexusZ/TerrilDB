<?php

declare(strict_types=1);

namespace App\Tests\Core\Query\Script;

use App\Core\Connection\Engine;
use App\Core\Driver\Exception\UnsupportedOperationException;
use App\Core\Driver\TargetConnection;
use App\Core\Guard\GuardReason;
use App\Core\Guard\StatementKind;
use App\Core\Query\Exception\QueryRefusedException;
use App\Core\Query\QueryRunner;
use App\Core\Query\Script\Exception\ScriptDryRunUnavailableException;
use App\Core\Query\Script\Exception\ScriptNeedsConfirmationException;
use App\Core\Query\Script\ScriptExecutor;
use App\Core\Query\Script\ScriptOptions;
use App\Core\Query\Script\ScriptResult;
use App\Core\Query\Script\ScriptService;
use App\Core\Query\Script\ScriptStatement;
use App\Core\Query\Script\ScriptStatus;
use App\Core\Query\WritePermissions;
use PHPUnit\Framework\TestCase;

final class ScriptServiceTest extends TestCase
{
    private function service(): ScriptService
    {
        return new ScriptService(new \App\Core\Guard\ScriptAnalyzer());
    }

    /**
     * @return array{TargetConnection, object{statements: list<ScriptStatement>, timeoutMs: int, dryRun: bool, calls: int}}
     */
    private function target(Engine $engine): array
    {
        $spy = new class implements ScriptExecutor {
            /** @var list<ScriptStatement> */
            public array $statements = [];
            public int $timeoutMs = 0;
            public bool $dryRun = false;
            public int $calls = 0;

            public function execute(array $statements, int $timeoutMs, bool $dryRun): ScriptResult
            {
                ++$this->calls;
                $this->statements = $statements;
                $this->timeoutMs = $timeoutMs;
                $this->dryRun = $dryRun;

                return new ScriptResult(ScriptStatus::Committed, $dryRun, [], null);
            }
        };

        return [new TargetConnection($engine, null, $this->createStub(QueryRunner::class), static function (): void {
        }, script: $spy), $spy];
    }

    public function testPreviewOnPostgreSqlIsAtomicAndAllowsADryRunEvenWithDdl(): void
    {
        $preview = $this->service()->preview("CREATE TABLE t (id int);\nINSERT INTO t VALUES (1);", Engine::PostgreSql, WritePermissions::all());

        self::assertTrue($preview->report->canRun());
        self::assertTrue($preview->capabilities->transactional);
        self::assertTrue($preview->capabilities->atomic);
        self::assertTrue($preview->capabilities->dryRunAvailable);
        self::assertNull($preview->capabilities->dryRunUnavailableReason);
        self::assertSame([], $preview->capabilities->warnings);
    }

    public function testPreviewOnMySqlWarnsThatMixingDataAndDdlCannotBeRolledBackAsAWhole(): void
    {
        foreach ([Engine::MySql, Engine::MariaDb] as $engine) {
            $preview = $this->service()->preview('INSERT INTO t VALUES (1); ALTER TABLE t ADD c int; INSERT INTO t VALUES (2);', $engine, WritePermissions::all());

            self::assertTrue($preview->capabilities->transactional);
            self::assertFalse($preview->capabilities->atomic);
            self::assertSame(['mixed_data_and_ddl'], $preview->capabilities->warnings);
            self::assertFalse($preview->capabilities->dryRunAvailable);
            self::assertSame('implicit_commit', $preview->capabilities->dryRunUnavailableReason);
        }
    }

    public function testPreviewOnMySqlWithDataOnlyIsAtomicWithADryRun(): void
    {
        $preview = $this->service()->preview('INSERT INTO t VALUES (1); UPDATE t SET a = 1 WHERE id = 1;', Engine::MySql, WritePermissions::all());

        self::assertTrue($preview->capabilities->atomic);
        self::assertTrue($preview->capabilities->dryRunAvailable);
        self::assertSame([], $preview->capabilities->warnings);
    }

    public function testPreviewOnMySqlWithDdlOnlyIsNotAtomicAndHasNoDryRun(): void
    {
        $preview = $this->service()->preview('CREATE TABLE a (id int); CREATE TABLE b (id int);', Engine::MySql, WritePermissions::all());

        self::assertFalse($preview->capabilities->atomic);
        self::assertSame(['ddl_not_transactional'], $preview->capabilities->warnings);
        self::assertFalse($preview->capabilities->dryRunAvailable);
    }

    public function testACallOnMySqlIsTreatedAsAnImplicitCommit(): void
    {
        $preview = $this->service()->preview('CALL cleanup();', Engine::MySql, WritePermissions::all());

        self::assertFalse($preview->capabilities->dryRunAvailable);
    }

    public function testClickHouseHasNoTransactionAndNoDryRun(): void
    {
        $preview = $this->service()->preview('INSERT INTO t VALUES (1); INSERT INTO t VALUES (2);', Engine::ClickHouse, WritePermissions::all());

        self::assertFalse($preview->capabilities->transactional);
        self::assertFalse($preview->capabilities->atomic);
        self::assertSame(['no_transaction'], $preview->capabilities->warnings);
        self::assertFalse($preview->capabilities->dryRunAvailable);
        self::assertSame('no_transaction', $preview->capabilities->dryRunUnavailableReason);
    }

    public function testAPreviewOfARefusedScriptStillReportsEveryVerdict(): void
    {
        $preview = $this->service()->preview("INSERT INTO t VALUES (1);\nLOAD DATA INFILE 'x' INTO TABLE t;", Engine::MySql, WritePermissions::all());

        self::assertFalse($preview->report->canRun());
        self::assertSame(1, $preview->report->firstRefused?->index);
    }

    public function testRedisIsNotConcerned(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->service()->preview('GET a', Engine::Redis, WritePermissions::all());
    }

    public function testARefusedStatementStopsTheWholeScriptBeforeAnyExecution(): void
    {
        [$target, $spy] = $this->target(Engine::PostgreSql);

        try {
            $this->service()->execute($target, "INSERT INTO t VALUES (1);\nCOPY t TO '/tmp/x';", new ScriptOptions(WritePermissions::all()), true);
            self::fail('A refusal was expected.');
        } catch (QueryRefusedException $e) {
            self::assertSame(GuardReason::ForbiddenConstruct, $e->reason);
        }
        self::assertSame(0, $spy->calls);
    }

    public function testTheRightsOfTheCallerApplyToEachStatement(): void
    {
        [$target, $spy] = $this->target(Engine::PostgreSql);

        try {
            $this->service()->execute($target, 'INSERT INTO t VALUES (1); CREATE TABLE u (id int);', new ScriptOptions(new WritePermissions(data: true)), true);
            self::fail('A refusal was expected.');
        } catch (QueryRefusedException $e) {
            self::assertSame(GuardReason::WriteNotPermitted, $e->reason);
        }
        self::assertSame(0, $spy->calls);
    }

    public function testANewDestructiveStatementWaitsForOneConfirmationForTheWholeScript(): void
    {
        [$target, $spy] = $this->target(Engine::PostgreSql);

        try {
            $this->service()->execute($target, 'DROP TABLE a; DROP TABLE b;', new ScriptOptions(WritePermissions::all()));
            self::fail('A confirmation was expected.');
        } catch (ScriptNeedsConfirmationException $e) {
            self::assertSame(2, $e->destructiveCount);
        }
        self::assertSame(0, $spy->calls);

        $this->service()->execute($target, 'DROP TABLE a; DROP TABLE b;', new ScriptOptions(WritePermissions::all()), true);
        self::assertSame(1, $spy->calls);
    }

    public function testTheExecutorReceivesTheStatementsKindsAndImplicitCommits(): void
    {
        [$target, $spy] = $this->target(Engine::MySql);

        $this->service()->execute($target, 'INSERT INTO t VALUES (1); CREATE TABLE u (id int); SELECT 1', new ScriptOptions(WritePermissions::all(), 60_000), true);

        self::assertSame([StatementKind::Write, StatementKind::Schema, StatementKind::Read], array_map(static fn (ScriptStatement $s): StatementKind => $s->kind, $spy->statements));
        self::assertSame([false, true, false], array_map(static fn (ScriptStatement $s): bool => $s->commitsImplicitly, $spy->statements));
        self::assertSame([0, 1, 2], array_map(static fn (ScriptStatement $s): int => $s->index, $spy->statements));
        self::assertSame(60_000, $spy->timeoutMs);
        self::assertFalse($spy->dryRun);
    }

    public function testADryRunThatTheEngineCannotDoIsRefusedWithItsReasonAndNothingRuns(): void
    {
        [$target, $spy] = $this->target(Engine::MySql);

        try {
            $this->service()->execute($target, 'INSERT INTO t VALUES (1); CREATE TABLE u (id int);', new ScriptOptions(WritePermissions::all(), dryRun: true), true);
            self::fail('A dry run was refused.');
        } catch (ScriptDryRunUnavailableException $e) {
            self::assertSame('implicit_commit', $e->reason);
        }
        self::assertSame(0, $spy->calls);
    }

    public function testADryRunIsPassedToTheExecutorWhenTheEngineCanDoIt(): void
    {
        [$target, $spy] = $this->target(Engine::PostgreSql);

        $result = $this->service()->execute($target, 'INSERT INTO t VALUES (1)', new ScriptOptions(WritePermissions::all(), dryRun: true), true);

        self::assertTrue($spy->dryRun);
        self::assertTrue($result->dryRun);
    }

    public function testATargetWithoutScriptSupportIsUnsupported(): void
    {
        $target = new TargetConnection(Engine::PostgreSql, null, $this->createStub(QueryRunner::class), static function (): void {
        });

        $this->expectException(UnsupportedOperationException::class);

        $this->service()->execute($target, 'SELECT 1', new ScriptOptions(WritePermissions::all()), true);
    }

    public function testAnEmptyScriptIsRefused(): void
    {
        [$target] = $this->target(Engine::PostgreSql);

        $this->expectException(QueryRefusedException::class);

        $this->service()->execute($target, '  ;  ', new ScriptOptions(WritePermissions::all()), true);
    }
}
