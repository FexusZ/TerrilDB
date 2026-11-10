<?php

declare(strict_types=1);

namespace App\Tests\Core\Guard;

use App\Core\Guard\Exception\UnsplittableScriptException;
use App\Core\Guard\ScriptSplitter;
use App\Core\Guard\SqlDialect;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ScriptSplitterTest extends TestCase
{
    /**
     * @return iterable<string, array{SqlDialect}>
     */
    public static function dialects(): iterable
    {
        yield 'MySQL' => [SqlDialect::MySql];
        yield 'PostgreSQL' => [SqlDialect::PostgreSql];
        yield 'ClickHouse' => [SqlDialect::ClickHouse];
    }

    #[DataProvider('dialects')]
    public function testSplitsOnSemicolonsAndTrims(SqlDialect $dialect): void
    {
        self::assertSame(['SELECT 1', 'DELETE FROM t WHERE id = 2'], (new ScriptSplitter())->split("SELECT 1;\n  DELETE FROM t WHERE id = 2 ;\n", $dialect));
    }

    #[DataProvider('dialects')]
    public function testASingleStatementWithoutSemicolonIsOnePiece(SqlDialect $dialect): void
    {
        self::assertSame(['SELECT 1'], (new ScriptSplitter())->split('SELECT 1', $dialect));
    }

    #[DataProvider('dialects')]
    public function testASemicolonInAStringOrAComment(SqlDialect $dialect): void
    {
        $script = "INSERT INTO t VALUES ('a;b'); -- un ; de commentaire\nSELECT 2 /* ; */";

        self::assertSame(["INSERT INTO t VALUES ('a;b')", "-- un ; de commentaire\nSELECT 2 /* ; */"], (new ScriptSplitter())->split($script, $dialect));
    }

    public function testASemicolonInADollarQuotedBody(): void
    {
        $script = 'CREATE FUNCTION f() RETURNS int AS $$ BEGIN RETURN 1; END; $$ LANGUAGE plpgsql; SELECT f()';

        self::assertSame(['CREATE FUNCTION f() RETURNS int AS $$ BEGIN RETURN 1; END; $$ LANGUAGE plpgsql', 'SELECT f()'], (new ScriptSplitter())->split($script, SqlDialect::PostgreSql));
    }

    #[DataProvider('dialects')]
    public function testEmptyStatementsAndCommentOnlyPiecesAreDropped(SqlDialect $dialect): void
    {
        self::assertSame(['SELECT 1'], (new ScriptSplitter())->split(";; /* rien */ ; SELECT 1 ;\n-- fin\n;", $dialect));
    }

    #[DataProvider('dialects')]
    public function testAnEmptyScriptHasNoStatement(SqlDialect $dialect): void
    {
        self::assertSame([], (new ScriptSplitter())->split("  \n-- seulement un commentaire\n", $dialect));
    }

    #[DataProvider('dialects')]
    public function testAnUnclosedStringRefusesTheWholeScript(SqlDialect $dialect): void
    {
        $this->expectException(UnsplittableScriptException::class);

        (new ScriptSplitter())->split("SELECT 1; SELECT 'ouvert; DROP TABLE t", $dialect);
    }

    public function testASemicolonInsideAnExecutableMySqlCommentRefusesTheScript(): void
    {
        $this->expectException(UnsplittableScriptException::class);

        (new ScriptSplitter())->split('SELECT 1 /*!50000 ; DROP TABLE t */', SqlDialect::MySql);
    }

    public function testTheSameTextIsNotAnExecutableCommentElsewhere(): void
    {
        self::assertSame(['SELECT 1 /*!50000 ; DROP TABLE t */', 'SELECT 2'], (new ScriptSplitter())->split('SELECT 1 /*!50000 ; DROP TABLE t */; SELECT 2', SqlDialect::PostgreSql));
    }

    public function testThePiecesTogetherAreNotLargerThanTheScript(): void
    {
        $script = str_repeat("SELECT 1;\n", 50);

        $pieces = (new ScriptSplitter())->split($script, SqlDialect::MySql);

        self::assertCount(50, $pieces);
    }
}
