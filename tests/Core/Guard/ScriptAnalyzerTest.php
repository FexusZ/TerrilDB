<?php

declare(strict_types=1);

namespace App\Tests\Core\Guard;

use App\Core\Connection\Engine;
use App\Core\Guard\Decision;
use App\Core\Guard\Exception\ScriptTooLargeException;
use App\Core\Guard\Exception\UnsplittableScriptException;
use App\Core\Guard\GuardReason;
use App\Core\Guard\ScriptAnalyzer;
use App\Core\Guard\StatementKind;
use App\Core\Query\WritePermissions;
use PHPUnit\Framework\TestCase;

final class ScriptAnalyzerTest extends TestCase
{
    public function testEveryStatementIsJudgedOnItsOwn(): void
    {
        $report = (new ScriptAnalyzer())->analyze("CREATE TABLE t (id int);\nINSERT INTO t VALUES (1);\nSELECT * FROM t;", Engine::PostgreSql, WritePermissions::all());

        self::assertCount(3, $report->steps);
        self::assertSame(['CREATE TABLE t (id int)', 'INSERT INTO t VALUES (1)', 'SELECT * FROM t'], array_map(static fn ($step): string => $step->sql, $report->steps));
        self::assertSame([StatementKind::Schema, StatementKind::Write, StatementKind::Read], array_map(static fn ($step): ?StatementKind => $step->verdict->kind, $report->steps));
        self::assertTrue($report->canRun());
        self::assertFalse($report->needsConfirmation());
    }

    public function testADestructiveStatementAsksOneConfirmationForTheWholeScript(): void
    {
        $report = (new ScriptAnalyzer())->analyze('INSERT INTO t VALUES (1); DROP TABLE old; DELETE FROM t;', Engine::MySql, WritePermissions::all());

        self::assertTrue($report->canRun());
        self::assertTrue($report->needsConfirmation());
        self::assertSame([Decision::Allowed, Decision::NeedsConfirmation, Decision::NeedsConfirmation], array_map(static fn ($step): Decision => $step->verdict->decision, $report->steps));
        self::assertSame(2, $report->destructiveCount());
    }

    public function testOneRefusedStatementStopsTheWholeScriptBeforeAnyExecution(): void
    {
        $report = (new ScriptAnalyzer())->analyze("INSERT INTO t VALUES (1);\nSELECT * FROM t INTO OUTFILE '/tmp/x';\nINSERT INTO t VALUES (2);", Engine::MySql, WritePermissions::all());

        self::assertFalse($report->canRun());
        self::assertNotNull($report->firstRefused);
        self::assertSame(1, $report->firstRefused->index);
        self::assertSame(GuardReason::ForbiddenConstruct, $report->firstRefused->verdict->reason);
        self::assertCount(3, $report->steps);
    }

    public function testMissingRightsRefuseTheScript(): void
    {
        $report = (new ScriptAnalyzer())->analyze('INSERT INTO t VALUES (1); CREATE TABLE u (id int);', Engine::PostgreSql, new WritePermissions(data: true));

        self::assertFalse($report->canRun());
        self::assertSame(GuardReason::WriteNotPermitted, $report->firstRefused?->verdict->reason);
        self::assertSame(1, $report->firstRefused->index);
    }

    public function testAnEmptyScriptRunsNothing(): void
    {
        $report = (new ScriptAnalyzer())->analyze("-- rien\n;", Engine::MySql, WritePermissions::all());

        self::assertSame([], $report->steps);
        self::assertFalse($report->canRun());
    }

    public function testAnUnclosedStringRefusesTheScript(): void
    {
        $this->expectException(UnsplittableScriptException::class);

        (new ScriptAnalyzer())->analyze("SELECT 1; SELECT 'x", Engine::PostgreSql, WritePermissions::all());
    }

    public function testTooManyStatementsAreRefused(): void
    {
        $this->expectException(ScriptTooLargeException::class);

        (new ScriptAnalyzer(maxStatements: 3))->analyze('SELECT 1; SELECT 2; SELECT 3; SELECT 4', Engine::MySql, WritePermissions::all());
    }

    public function testAScriptOverTheSizeLimitIsRefused(): void
    {
        $this->expectException(ScriptTooLargeException::class);

        (new ScriptAnalyzer(maxBytes: 20))->analyze('SELECT 1; SELECT 2; SELECT 3', Engine::MySql, WritePermissions::all());
    }

    public function testRedisHasNoScripts(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        (new ScriptAnalyzer())->analyze('GET a', Engine::Redis, WritePermissions::all());
    }
}
