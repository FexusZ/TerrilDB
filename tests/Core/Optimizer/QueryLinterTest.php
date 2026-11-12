<?php

declare(strict_types=1);

namespace App\Tests\Core\Optimizer;

use App\Core\Connection\Engine;
use App\Core\Optimizer\LintRule;
use App\Core\Optimizer\QueryLinter;
use App\Core\Optimizer\Severity;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class QueryLinterTest extends TestCase
{
    /**
     * @return list<string>
     */
    private static function rules(Engine $engine, string $sql): array
    {
        return array_map(static fn ($f): string => $f->rule->value, (new QueryLinter())->lint($engine, $sql));
    }

    /**
     * @return iterable<string, array{string, LintRule}>
     */
    public static function positives(): iterable
    {
        yield 'year' => ['SELECT id FROM orders WHERE YEAR(ordered_at) = 2026', LintRule::FunctionOnColumn];
        yield 'lower qualified' => ["SELECT id FROM customers c WHERE LOWER(c.email) = 'a@b.c'", LintRule::FunctionOnColumn];
        yield 'after and' => ['SELECT id FROM orders WHERE status = 1 AND DATE(ordered_at) = CURRENT_DATE', LintRule::FunctionOnColumn];
        yield 'in parens' => ["SELECT id FROM orders WHERE (status = 1 OR (UPPER(status) = 'X'))", LintRule::FunctionOnColumn];
        yield 'in join' => ['SELECT a.id FROM a JOIN b ON LOWER(a.k) = b.k', LintRule::FunctionOnColumn];
        yield 'in subquery' => ['SELECT id FROM t WHERE id IN (SELECT i FROM u WHERE MONTH(d) = 3)', LintRule::FunctionOnColumn];
        yield 'extract' => ['SELECT id FROM t WHERE EXTRACT(YEAR FROM d) = 2026', LintRule::FunctionOnColumn];
        yield 'arithmetic' => ['SELECT id FROM t WHERE price + 1 = 10', LintRule::ArithmeticOnColumn];
        yield 'arithmetic qualified' => ['SELECT id FROM t WHERE t.qty * 2 > 10', LintRule::ArithmeticOnColumn];
        yield 'leading wildcard' => ["SELECT id FROM t WHERE name LIKE '%son'", LintRule::LeadingWildcard];
        yield 'ilike' => ["SELECT id FROM t WHERE t.name ILIKE '%son'", LintRule::LeadingWildcard];
        yield 'select star' => ['SELECT * FROM t', LintRule::SelectStar];
        yield 'qualified star' => ['SELECT o.*, c.email FROM o JOIN c ON c.id = o.c_id', LintRule::SelectStar];
        yield 'offset' => ['SELECT id FROM t ORDER BY id LIMIT 20 OFFSET 50000', LintRule::LargeOffset];
        yield 'not in' => ['SELECT id FROM t WHERE id NOT IN (SELECT t_id FROM u)', LintRule::NotInSubquery];
        yield 'for update' => ['SELECT id FROM t WHERE id = 1 FOR UPDATE', LintRule::RowLock];
        yield 'for share skip locked' => ['SELECT id FROM t FOR SHARE SKIP LOCKED', LintRule::RowLock];
        yield 'recursive' => ['WITH RECURSIVE n(i) AS (SELECT 1 UNION ALL SELECT i + 1 FROM n WHERE i < 5) SELECT i FROM n', LintRule::RecursiveCte];
    }

    #[DataProvider('positives')]
    public function testDetectsThePattern(string $sql, LintRule $expected): void
    {
        self::assertContains($expected->value, self::rules(Engine::PostgreSql, $sql));
        self::assertContains($expected->value, self::rules(Engine::MySql, $sql));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function negatives(): iterable
    {
        yield 'plain equality' => ['SELECT id FROM t WHERE status = 1'];
        yield 'range on column' => ["SELECT id FROM t WHERE ordered_at >= '2026-01-01' AND ordered_at < '2027-01-01'"];
        yield 'function on the constant side' => ['SELECT id FROM t WHERE d = DATE(NOW())'];
        yield 'function with constants only' => ['SELECT id FROM t WHERE COALESCE(1, 2) = x'];
        yield 'function in select list' => ['SELECT YEAR(d) FROM t WHERE id = 3'];
        yield 'function in group by' => ['SELECT YEAR(d), COUNT(*) FROM t GROUP BY YEAR(d)'];
        yield 'function in having' => ['SELECT k FROM t GROUP BY k HAVING MAX(d) = 3'];
        yield 'function in a string' => ["SELECT id FROM t WHERE note = 'YEAR(d) = 1'"];
        yield 'function in a comment' => ['SELECT id FROM t WHERE x = 1 /* YEAR(d) = 1 */'];
        yield 'variable argument' => ['SELECT id FROM t WHERE LOWER(@name) = x'];
        yield 'interval arithmetic' => ["SELECT id FROM t WHERE d - INTERVAL '1' DAY < x"];
        yield 'trailing wildcard' => ["SELECT id FROM t WHERE name LIKE 'abc%'"];
        yield 'wildcard in the middle' => ["SELECT id FROM t WHERE name LIKE 'a%c'"];
        yield 'wildcard in a comment' => ["SELECT id FROM t WHERE name LIKE 'a' -- '%x'"];
        yield 'count star' => ['SELECT COUNT(*) FROM t'];
        yield 'multiplication in select' => ['SELECT a * 2 FROM t'];
        yield 'exists star' => ['SELECT id FROM t WHERE EXISTS (SELECT * FROM u WHERE u.t_id = t.id)'];
        yield 'small offset' => ['SELECT id FROM t ORDER BY id LIMIT 20 OFFSET 40'];
        yield 'not in list' => ['SELECT id FROM t WHERE id NOT IN (1, 2, 3)'];
        yield 'not exists' => ['SELECT id FROM t WHERE NOT EXISTS (SELECT 1 FROM u WHERE u.t_id = t.id)'];
        yield 'cast of constant' => ["SELECT id FROM t WHERE x = CAST('1' AS SIGNED)"];
        yield 'plain recursive word' => ['SELECT recursive FROM t'];
        yield 'between' => ['SELECT id FROM t WHERE d BETWEEN 1 AND 5 AND status = 2'];
        yield 'update is out of scope' => ['UPDATE t SET a = 1 WHERE YEAR(d) = 1'];
        yield 'two statements' => ['SELECT * FROM t; SELECT * FROM u'];
        yield 'unterminated string' => ["SELECT * FROM t WHERE a = 'x"];
    }

    #[DataProvider('negatives')]
    public function testStaysSilent(string $sql): void
    {
        self::assertSame([], self::rules(Engine::PostgreSql, $sql), $sql);
        self::assertSame([], self::rules(Engine::MySql, $sql), $sql);
    }

    public function testLimitWithCommaIsMySqlOnly(): void
    {
        self::assertSame(['large_offset'], self::rules(Engine::MariaDb, 'SELECT id FROM t ORDER BY id LIMIT 50000, 20'));
    }

    public function testBetweenDoesNotSplitThePredicate(): void
    {
        self::assertSame(['function_on_column'], self::rules(Engine::MySql, 'SELECT id FROM t WHERE x BETWEEN 1 AND 5 AND YEAR(d) = 3'));
    }

    public function testDoubleQuotesAreAStringInMySqlAndAnIdentifierInPostgreSql(): void
    {
        $sql = 'SELECT id FROM t WHERE LOWER("Name") = 1';

        self::assertSame(['function_on_column'], self::rules(Engine::PostgreSql, $sql));
        self::assertSame([], self::rules(Engine::MySql, $sql));
    }

    public function testEveryFindingIsFrenchAdvice(): void
    {
        $findings = (new QueryLinter())->lint(Engine::PostgreSql, 'SELECT id FROM orders WHERE YEAR(ordered_at) = 2026');

        self::assertCount(1, $findings);
        self::assertSame(Severity::Warning, $findings[0]->severity);
        self::assertSame(['ordered_at'], $findings[0]->columns);
        self::assertStringContainsString('ordered_at', $findings[0]->message);
        self::assertNotNull($findings[0]->suggestion);
    }

    public function testSuggestionsFollowTheEngine(): void
    {
        $sql = 'SELECT id FROM orders WHERE YEAR(ordered_at) = 2026';
        $pg = (new QueryLinter())->lint(Engine::PostgreSql, $sql)[0]->suggestion;
        $my = (new QueryLinter())->lint(Engine::MySql, $sql)[0]->suggestion;

        self::assertStringContainsString('expression', (string) $pg);
        self::assertStringContainsString('générée', (string) $my);
    }

    public function testClickHouseOnlyKeepsThePatternsThatSpeakToIt(): void
    {
        self::assertSame(['select_star'], self::rules(Engine::ClickHouse, 'SELECT * FROM t WHERE YEAR(d) = 1 AND name LIKE \'%x\''));
        self::assertSame(['large_offset'], self::rules(Engine::ClickHouse, 'SELECT id FROM t LIMIT 20 OFFSET 99999'));
    }

    public function testRedisIsNotLinted(): void
    {
        self::assertSame([], self::rules(Engine::Redis, 'GET a'));
    }

    public function testNeverThrowsOnGarbage(): void
    {
        foreach (['', '   ', '(((', ')))', 'SELECT', 'SELECT (', "SELECT 'a", 'WHERE', 'SELECT * FROM t WHERE ('] as $sql) {
            (new QueryLinter())->lint(Engine::MySql, $sql);
            $this->addToAssertionCount(1);
        }
    }
}
