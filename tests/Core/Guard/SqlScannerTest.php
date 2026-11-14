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
        yield 'ClickHouse' => [SqlDialect::ClickHouse];
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

    public function testClickHouseStringsAndIdentifiersUseBackslashEscapes(): void
    {
        self::assertSame([['SELECT']], $this->words("select 'a\\'; drop table t; --'", SqlDialect::ClickHouse));
        self::assertSame([['SELECT', 'FROM', 'T']], $this->words('select "a\\"; drop table x; --" from t', SqlDialect::ClickHouse));
        self::assertSame([['SELECT', 'FROM', 'T']], $this->words('select `a\\`; drop table x; --` from t', SqlDialect::ClickHouse));
    }

    public function testClickHouseCommentsNeedNoWhitespaceAndHashStartsOne(): void
    {
        self::assertSame([['SELECT']], $this->words('select 1--1; drop table x', SqlDialect::ClickHouse));
        self::assertSame([['SELECT']], $this->words("select 1 # drop table x\n", SqlDialect::ClickHouse));
        self::assertSame([['SELECT']], $this->words("select 1 #! drop table x\n", SqlDialect::ClickHouse));
    }

    public function testClickHouseHasNoExecutableCommentsAndBlockCommentsDoNotNest(): void
    {
        self::assertSame([['SELECT']], $this->words('select 1 /*! ; drop table x */', SqlDialect::ClickHouse));
        self::assertSame([['SELECT', 'DELETE', 'FROM', 'T']], $this->words('select /* a /* drop table t; */ delete from t; */', SqlDialect::ClickHouse));
    }

    public function testClickHouseHeredocsHideTheirContent(): void
    {
        self::assertSame([['SELECT']], $this->words('select $$a; drop table t$$', SqlDialect::ClickHouse));
        self::assertSame([['SELECT']], $this->words('select $tag$a; $$ drop table t$tag$', SqlDialect::ClickHouse));
    }

    #[DataProvider('dialects')]
    public function testAStatementKnowsWhichWordsAreCalledLikeFunctions(SqlDialect $dialect): void
    {
        $statement = (new SqlScanner())->scan("select file ('x'), url, count(*) from t where a in (1)", $dialect)->statements[0];

        self::assertTrue($statement->calls('FILE'));
        self::assertTrue($statement->calls('COUNT'));
        self::assertFalse($statement->calls('URL'));
        self::assertFalse($statement->calls('FROM'));
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
        yield 'ClickHouse string' => ["select 'abc", SqlDialect::ClickHouse];
        yield 'ClickHouse backtick' => ['select `abc', SqlDialect::ClickHouse];
        yield 'ClickHouse quoted identifier' => ['select "abc', SqlDialect::ClickHouse];
        yield 'ClickHouse heredoc' => ['select $a$ abc', SqlDialect::ClickHouse];
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

    #[DataProvider('dialects')]
    public function testWordsRememberTheirParenthesisDepth(SqlDialect $dialect): void
    {
        $statement = (new SqlScanner())->scan("update t set a = (select b from u where u.id = 1) where c = ('(' || d)", $dialect)->statements[0];

        self::assertTrue($statement->hasTopLevel('UPDATE'));
        self::assertTrue($statement->hasTopLevel('WHERE'));
        self::assertTrue($statement->has('SELECT'));
        self::assertFalse($statement->hasTopLevel('SELECT'));

        $withoutOuterWhere = (new SqlScanner())->scan('update t set a = (select b from u where u.id = 1)', $dialect)->statements[0];
        self::assertTrue($withoutOuterWhere->has('WHERE'));
        self::assertFalse($withoutOuterWhere->hasTopLevel('WHERE'));
    }

    #[DataProvider('dialects')]
    public function testDepthStartsOverForEachStatement(SqlDialect $dialect): void
    {
        $statements = (new SqlScanner())->scan('select (1; delete from t', $dialect)->statements;

        self::assertTrue($statements[1]->hasTopLevel('DELETE'));
    }

    #[DataProvider('dialects')]
    public function testTellsAFunctionCallFromTheSameWordUsedAsAKeyword(SqlDialect $dialect): void
    {
        $statement = (new SqlScanner())->scan("with x as (select 1) select replace(a, 'b', 'c') from t; replace into t values (1)", $dialect)->statements;

        $words = $statement[0]->words;
        $replace = array_search('REPLACE', $words, true);
        self::assertIsInt($replace);
        self::assertTrue($statement[0]->isCallAt($replace));
        self::assertFalse($statement[0]->isCallAt((int) array_search('FROM', $words, true)));
        self::assertFalse($statement[1]->isCallAt(0));
    }

    #[DataProvider('dialects')]
    public function testFindsTheVerbThatEndsTheCommonTableExpressions(SqlDialect $dialect): void
    {
        $head = static fn (string $sql): ?string => (new SqlScanner())->scan($sql, $dialect)->statements[0]->withHead();

        self::assertSame('SELECT', $head('with x as (select 1), y(a) as (select 2) select * from x'));
        self::assertSame('SELECT', $head('with recursive n(i) as (select 1 union all select i + 1 from n) select i from n'));
        // Une tête entre parenthèses n'est pas reconnue : le garde la refuse par prudence.
        self::assertNull($head('with x as (select 1) (select 2)'));
        self::assertSame('UPDATE', $head('with x as (select 1) update t set a = 1'));
        self::assertSame('DROP', $head('with x as (select 1) drop table t'));
        self::assertSame('DELETE', $head('with x as (select 1) delete from t'));
        self::assertNull($head('with x as (select 1)'));
        self::assertNull($head('select 1'));
    }

    #[DataProvider('dialects')]
    public function testLooksForTheWhereOfEachRowChangeInItsOwnLevel(SqlDialect $dialect): void
    {
        $unbounded = static fn (string $sql): bool => (new SqlScanner())->scan($sql, $dialect)->statements[0]->hasUnboundedRowChange();

        self::assertTrue($unbounded('delete from t'));
        self::assertFalse($unbounded('delete from t where a = 1'));
        self::assertTrue($unbounded('update t set a = (select b from u where u.id = 1)'));
        self::assertTrue($unbounded('with d as (delete from t returning *) select * from d where a = 1'));
        self::assertFalse($unbounded('with d as (delete from t where id = 3 returning *) select * from d'));
        self::assertTrue($unbounded('with d as (delete from t where id = 3 returning *), e as (update u set a = 1 returning *) select 1'));
        // Deux groupes voisins : le WHERE de l'un n'est pas celui de l'autre.
        self::assertTrue($unbounded('with d as (delete from t returning *), e as (select 1 where true) select 1'));
        self::assertFalse($unbounded('insert into t values (1) on duplicate key update a = 2'));
        self::assertFalse($unbounded('insert into t values (1) on conflict (id) do update set a = 2'));
        self::assertFalse($unbounded('select * from t for update'));
        self::assertTrue($unbounded('merge into t using s on t.id = s.id when matched then delete'));
    }
}
