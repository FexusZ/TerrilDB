<?php

declare(strict_types=1);

namespace App\Tests\Core\Guard;

use App\Core\Guard\SqlDialect;
use App\Core\Guard\SqlScanner;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SqlScannerTest extends TestCase
{
    /**
     * @return list<list<string>> les mots de chaque instruction
     */
    private function words(string $sql, SqlDialect $dialect): array
    {
        return array_map(
            static fn ($statement): array => $statement->words,
            (new SqlScanner())->scan($sql, $dialect)->statements,
        );
    }

    /**
     * @return iterable<string, array{SqlDialect}>
     */
    public static function dialects(): iterable
    {
        yield 'MySQL' => [SqlDialect::MySql];
        yield 'PostgreSQL' => [SqlDialect::PostgreSql];
    }

    #[DataProvider('dialects')]
    public function testSplitsStatementsAndDropsEmptyOnes(SqlDialect $dialect): void
    {
        self::assertSame([['SELECT', 'A'], ['DROP', 'TABLE', 'T']], $this->words('select a; ;; drop table t;', $dialect));
    }

    #[DataProvider('dialects')]
    public function testWordsAreUppercasedAndNumbersAreNotWords(SqlDialect $dialect): void
    {
        self::assertSame([['SELECT', 'FOO_BAR', 'FROM', 'T1']], $this->words('SeLeCt 12, 1e5, 0x1F, foo_bar from t1', $dialect));
    }

    #[DataProvider('dialects')]
    public function testCommentsHideTheirContent(SqlDialect $dialect): void
    {
        self::assertSame([['SELECT', 'A']], $this->words("select /* drop table t; */ a -- delete from t\n", $dialect));
    }

    #[DataProvider('dialects')]
    public function testStringsHideTheirContent(SqlDialect $dialect): void
    {
        self::assertSame([['SELECT']], $this->words("select 'a; drop table t', 'it''s; delete from t'", $dialect));
    }

    public function testMySqlBackslashEscapesKeepTheStringOpen(): void
    {
        self::assertSame([['SELECT']], $this->words("select 'a\\'; drop table t; --'", SqlDialect::MySql));
    }

    public function testPostgreSqlPlainStringsDoNotUseBackslashEscapes(): void
    {
        self::assertSame([['SELECT'], ['DROP', 'TABLE', 'T']], $this->words("select 'a\\'; drop table t; --'", SqlDialect::PostgreSql));
    }

    public function testPostgreSqlEscapeStringsDoUseBackslashEscapes(): void
    {
        self::assertSame([['SELECT']], $this->words("select E'a\\'; drop table t; --'", SqlDialect::PostgreSql));
    }

    public function testPostgreSqlDollarQuotingHidesItsContent(): void
    {
        self::assertSame([['SELECT']], $this->words('select $$a; drop table t$$', SqlDialect::PostgreSql));
        self::assertSame([['SELECT']], $this->words('select $tag$a; $$ drop table t$tag$', SqlDialect::PostgreSql));
    }

    public function testPostgreSqlPositionalParametersAreNotDollarQuotes(): void
    {
        self::assertSame([['SELECT', 'A', 'WHERE', 'B'], ['DROP', 'TABLE', 'T']], $this->words('select a where b = $1; drop table t', SqlDialect::PostgreSql));
    }

    public function testQuotedIdentifiersHideTheirContent(): void
    {
        self::assertSame([['SELECT', 'FROM', 'T']], $this->words('select "drop" from "x;y" t', SqlDialect::PostgreSql));
        self::assertSame([['SELECT', 'FROM', 'T']], $this->words('select `drop` from `a;b` t', SqlDialect::MySql));
    }

    public function testMySqlDashDashNeedsAWhitespaceToBeAComment(): void
    {
        self::assertSame([['SELECT'], ['DROP', 'TABLE', 'X']], $this->words('select 1--1; drop table x', SqlDialect::MySql));
        self::assertSame([['SELECT']], $this->words('select 1--1; drop table x', SqlDialect::PostgreSql));
        self::assertSame([['SELECT']], $this->words('select 1-- drop table x', SqlDialect::MySql));
    }

    public function testHashIsACommentInMySqlOnly(): void
    {
        self::assertSame([['SELECT']], $this->words("select 1 # drop table x\n", SqlDialect::MySql));
        self::assertSame([['SELECT', 'DROP', 'TABLE', 'X']], $this->words("select 1 # drop table x\n", SqlDialect::PostgreSql));
    }

    public function testMySqlExecutableCommentsAreCode(): void
    {
        self::assertSame([['SELECT'], ['DROP', 'TABLE', 'X']], $this->words('select 1 /*! ; drop table x */', SqlDialect::MySql));
        self::assertSame([['SELECT'], ['DROP', 'TABLE', 'X']], $this->words('select 1 /*!50000 ; drop table x */', SqlDialect::MySql));
        self::assertSame([['SELECT'], ['DROP', 'TABLE', 'X']], $this->words('select 1 /*M! ; drop table x */', SqlDialect::MySql));
        self::assertSame([['SELECT']], $this->words('select 1 /*! ; drop table x */', SqlDialect::PostgreSql));
    }

    public function testOptimizerHintsAreComments(): void
    {
        self::assertSame([['SELECT', 'A']], $this->words('select /*+ no_index(t) */ a', SqlDialect::MySql));
    }

    public function testPostgreSqlBlockCommentsNestButMySqlOnesDoNot(): void
    {
        $sql = 'select /* a /* drop table t; */ delete from t; */';

        self::assertSame([['SELECT']], $this->words($sql, SqlDialect::PostgreSql));
        self::assertSame([['SELECT', 'DELETE', 'FROM', 'T']], $this->words($sql, SqlDialect::MySql));
    }

    /**
     * @return iterable<string, array{string, SqlDialect}>
     */
    public static function unterminated(): iterable
    {
        yield 'MySQL string' => ["select 'abc", SqlDialect::MySql];
        yield 'PostgreSQL string' => ["select 'abc", SqlDialect::PostgreSql];
        yield 'block comment' => ['select 1 /* abc', SqlDialect::PostgreSql];
        yield 'MySQL block comment' => ['select 1 /* abc', SqlDialect::MySql];
        yield 'backtick' => ['select `abc', SqlDialect::MySql];
        yield 'quoted identifier' => ['select "abc', SqlDialect::PostgreSql];
        yield 'dollar quote' => ['select $a$ abc', SqlDialect::PostgreSql];
        yield 'trailing backslash' => ["select 'abc\\", SqlDialect::MySql];
    }

    #[DataProvider('unterminated')]
    public function testUnterminatedConstructsMakeTheScanIncomplete(string $sql, SqlDialect $dialect): void
    {
        self::assertFalse((new SqlScanner())->scan($sql, $dialect)->complete);
    }

    #[DataProvider('dialects')]
    public function testACleanScanIsComplete(SqlDialect $dialect): void
    {
        self::assertTrue((new SqlScanner())->scan("select 'a' /* b */ -- c\n", $dialect)->complete);
    }

    #[DataProvider('dialects')]
    public function testEmptyInputHasNoStatement(SqlDialect $dialect): void
    {
        $scan = (new SqlScanner())->scan("  -- nothing\n ; ", $dialect);

        self::assertSame([], $scan->statements);
        self::assertTrue($scan->complete);
    }

    #[DataProvider('dialects')]
    public function testAStatementKnowsItsFirstWordAndWordSequences(SqlDialect $dialect): void
    {
        $statement = (new SqlScanner())->scan('select a into outfile x for update', $dialect)->statements[0];

        self::assertSame('SELECT', $statement->first());
        self::assertTrue($statement->has('OUTFILE'));
        self::assertFalse($statement->has('DUMPFILE'));
        self::assertTrue($statement->hasSequence('INTO', 'OUTFILE'));
        self::assertTrue($statement->hasSequence('FOR', 'UPDATE'));
        self::assertFalse($statement->hasSequence('OUTFILE', 'INTO'));
    }
}
