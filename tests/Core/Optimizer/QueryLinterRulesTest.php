<?php

declare(strict_types=1);

namespace App\Tests\Core\Optimizer;

use App\Core\Connection\Engine;
use App\Core\Optimizer\LintFinding;
use App\Core\Optimizer\QueryLinter;
use App\Core\Schema\ColumnDefinition;
use App\Core\Schema\IndexDefinition;
use App\Core\Schema\TableDefinition;
use App\Core\Schema\TableKind;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Les règles qui ont besoin de la structure (type d'une colonne, index, taille estimée) ou d'une forme précise de requête :
 * type_mismatch, or_on_columns, distinct_join, count_star_large, random_order.
 */
final class QueryLinterRulesTest extends TestCase
{
    private function orders(?int $rows = null): TableDefinition
    {
        $columns = [];
        foreach (['id' => 'integer', 'status' => 'varchar(20)', 'code' => 'character varying(8)', 'note' => 'text', 'qty' => 'integer', 'email' => 'varchar(80)', 'amount' => 'numeric(10,2)'] as $name => $type) {
            $columns[] = new ColumnDefinition($name, $type, true, null, false, null);
        }

        return new TableDefinition('orders', TableKind::Table, $columns, [
            new IndexDefinition('orders_pkey', ['id'], true, true),
            new IndexDefinition('idx_status', ['status'], false, false),
            new IndexDefinition('idx_qty', ['qty'], false, false),
        ], [], null, $rows);
    }

    /**
     * @return list<LintFinding>
     */
    private function lint(Engine $engine, string $sql, ?TableDefinition $table = null): array
    {
        return (new QueryLinter())->lint($engine, $sql, static fn (string $name): ?TableDefinition => $table);
    }

    /**
     * @return list<string>
     */
    private function rules(Engine $engine, string $sql, ?TableDefinition $table = null): array
    {
        return array_map(static fn (LintFinding $f): string => $f->rule->value, $this->lint($engine, $sql, $table));
    }

    private function finding(Engine $engine, string $sql, string $rule, ?TableDefinition $table = null): ?LintFinding
    {
        foreach ($this->lint($engine, $sql, $table) as $finding) {
            if ($finding->rule->value === $rule) {
                return $finding;
            }
        }

        return null;
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function mismatches(): iterable
    {
        yield 'equal' => ['SELECT id FROM orders WHERE status = 5'];
        yield 'different' => ['SELECT id FROM orders WHERE note <> 5'];
        yield 'greater or equal' => ['SELECT id FROM orders WHERE code >= 5'];
        yield 'decimal number' => ['SELECT id FROM orders WHERE email = 1.5'];
        yield 'qualified with alias' => ['SELECT o.id FROM orders o WHERE o.status = 5'];
        yield 'after and' => ['SELECT id FROM orders WHERE qty = 1 AND status = 5'];
    }

    #[DataProvider('mismatches')]
    public function testATextColumnComparedToANumber(string $sql): void
    {
        foreach ([Engine::MySql, Engine::MariaDb, Engine::PostgreSql] as $engine) {
            self::assertContains('type_mismatch', $this->rules($engine, $sql, $this->orders()), $engine->value.' '.$sql);
        }
    }

    public function testTheTextNamesTheIndexAndTheEngineBehavior(): void
    {
        $indexed = $this->finding(Engine::MySql, 'SELECT id FROM orders WHERE status = 5', 'type_mismatch', $this->orders());
        $plain = $this->finding(Engine::MySql, 'SELECT id FROM orders WHERE note = 5', 'type_mismatch', $this->orders());
        $pg = $this->finding(Engine::PostgreSql, 'SELECT id FROM orders WHERE status = 5', 'type_mismatch', $this->orders());

        self::assertNotNull($indexed);
        self::assertStringContainsString('idx_status', $indexed->message);
        self::assertSame(['status'], $indexed->columns);
        self::assertNotNull($plain);
        self::assertStringNotContainsString('idx_', $plain->message);
        self::assertNotNull($pg);
        self::assertStringContainsString('refuse', $pg->message);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function notMismatches(): iterable
    {
        yield 'text against text' => ["SELECT id FROM orders WHERE status = '5'"];
        yield 'integer against number' => ['SELECT id FROM orders WHERE qty = 5'];
        yield 'integer against a string' => ["SELECT id FROM orders WHERE qty = '5'"];
        yield 'decimal against number' => ['SELECT id FROM orders WHERE amount = 5'];
        yield 'unknown column' => ['SELECT id FROM orders WHERE nope = 5'];
        yield 'column against column' => ['SELECT id FROM orders WHERE status = qty'];
        yield 'number then arithmetic' => ['SELECT id FROM orders WHERE status = 5 + qty'];
        yield 'negative number' => ['SELECT id FROM orders WHERE status = -5'];
        yield 'hexadecimal' => ['SELECT id FROM orders WHERE status = 0x41'];
        yield 'text compared in a function' => ['SELECT id FROM orders WHERE LENGTH(status) = 5'];
        yield 'join, no table lookup' => ['SELECT o.id FROM orders o JOIN orders p ON p.id = o.id WHERE o.status = 5'];
        yield 'foreign qualifier' => ['SELECT o.id FROM orders o WHERE x.status = 5'];
    }

    #[DataProvider('notMismatches')]
    public function testNoTypeMismatchHere(string $sql): void
    {
        foreach ([Engine::MySql, Engine::PostgreSql] as $engine) {
            self::assertNotContains('type_mismatch', $this->rules($engine, $sql, $this->orders()), $sql);
        }
    }

    public function testNoTypeMismatchWithoutTheStructureOrOnClickHouse(): void
    {
        self::assertNotContains('type_mismatch', $this->rules(Engine::MySql, 'SELECT id FROM orders WHERE status = 5', null));
        self::assertNotContains('type_mismatch', $this->rules(Engine::ClickHouse, 'SELECT id FROM orders WHERE status = 5', $this->orders()));
        self::assertSame([], (new QueryLinter())->lint(Engine::MySql, 'SELECT id FROM orders WHERE status = 5'));
    }

    public function testAnOrKeepsAnIndexUnusedWhenOneSideHasNone(): void
    {
        $finding = $this->finding(Engine::MySql, "SELECT id FROM orders WHERE qty = 3 OR note = 'x'", 'or_on_columns', $this->orders());

        self::assertNotNull($finding);
        self::assertSame(['note'], $finding->columns);
        self::assertStringContainsString('idx_qty', $finding->message);
        self::assertNotNull($this->finding(Engine::PostgreSql, "SELECT id FROM orders WHERE note = 'x' OR qty IN (1, 2)", 'or_on_columns', $this->orders()));
        self::assertNotNull($this->finding(Engine::MariaDb, "SELECT o.id FROM orders o WHERE o.qty > 3 OR o.note LIKE 'a%' OR o.status = 'z'", 'or_on_columns', $this->orders()));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function notOr(): iterable
    {
        yield 'both indexed' => ["SELECT id FROM orders WHERE qty = 3 OR status = 'x'"];
        yield 'none indexed' => ["SELECT id FROM orders WHERE note = 'x' OR email = 'y'"];
        yield 'same column' => ['SELECT id FROM orders WHERE note = 1 OR note = 2'];
        yield 'mixed with and' => ["SELECT id FROM orders WHERE qty = 3 AND note = 'x' OR status = 'y'"];
        yield 'inside parentheses' => ["SELECT id FROM orders WHERE (qty = 3 OR note = 'x') AND id = 1"];
        yield 'function on a side' => ["SELECT id FROM orders WHERE LOWER(note) = 'x' OR qty = 3"];
        yield 'unknown column' => ["SELECT id FROM orders WHERE qty = 3 OR nope = 'x'"];
        yield 'between' => ["SELECT id FROM orders WHERE qty BETWEEN 1 AND 3 OR note = 'x'"];
        yield 'in having only' => ['SELECT qty FROM orders GROUP BY qty HAVING qty = 1 OR MAX(id) = 2'];
    }

    #[DataProvider('notOr')]
    public function testNoOrAdvice(string $sql): void
    {
        self::assertNotContains('or_on_columns', $this->rules(Engine::MySql, $sql, $this->orders()), $sql);
        self::assertNotContains('or_on_columns', $this->rules(Engine::MySql, "SELECT id FROM orders WHERE qty = 3 OR note = 'x'", null), 'without structure');
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function distinctJoins(): iterable
    {
        yield 'columns' => ['SELECT DISTINCT o.id, o.status FROM orders o JOIN items i ON i.order_id = o.id'];
        yield 'star' => ['SELECT DISTINCT o.* FROM orders o INNER JOIN items i ON i.order_id = o.id WHERE i.sku = 3'];
        yield 'name as qualifier' => ['SELECT DISTINCT orders.id FROM orders JOIN items ON items.order_id = orders.id'];
        yield 'as alias in the list' => ['SELECT DISTINCT o.id AS k FROM orders AS o JOIN items i ON i.order_id = o.id JOIN users u ON u.id = o.u'];
    }

    #[DataProvider('distinctJoins')]
    public function testDistinctOverAJoinOnlyToTestExistence(string $sql): void
    {
        foreach ([Engine::MySql, Engine::PostgreSql] as $engine) {
            self::assertContains('distinct_join', $this->rules($engine, $sql), $sql);
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function notDistinctJoins(): iterable
    {
        yield 'column of the joined table' => ['SELECT DISTINCT i.sku FROM orders o JOIN items i ON i.order_id = o.id'];
        yield 'both tables' => ['SELECT DISTINCT o.id, i.sku FROM orders o JOIN items i ON i.order_id = o.id'];
        yield 'left join' => ['SELECT DISTINCT o.id FROM orders o LEFT JOIN items i ON i.order_id = o.id'];
        yield 'cross join' => ['SELECT DISTINCT o.id FROM orders o CROSS JOIN items i'];
        yield 'comma join' => ['SELECT DISTINCT o.id FROM orders o, items i WHERE i.order_id = o.id'];
        yield 'no join' => ['SELECT DISTINCT o.id FROM orders o'];
        yield 'unqualified columns' => ['SELECT DISTINCT id FROM orders o JOIN items i ON i.order_id = o.id'];
        yield 'count distinct' => ['SELECT COUNT(DISTINCT o.id) FROM orders o JOIN items i ON i.order_id = o.id'];
        yield 'distinct on' => ['SELECT DISTINCT ON (o.id) o.id FROM orders o JOIN items i ON i.order_id = o.id'];
        yield 'group by' => ['SELECT DISTINCT o.id FROM orders o JOIN items i ON i.order_id = o.id GROUP BY o.id'];
        yield 'subquery' => ['SELECT DISTINCT o.id FROM orders o JOIN (SELECT order_id FROM items) i ON i.order_id = o.id'];
        yield 'expression' => ['SELECT DISTINCT o.id + 1 FROM orders o JOIN items i ON i.order_id = o.id'];
    }

    #[DataProvider('notDistinctJoins')]
    public function testNoDistinctJoinAdvice(string $sql): void
    {
        self::assertNotContains('distinct_join', $this->rules(Engine::PostgreSql, $sql), $sql);
        self::assertNotContains('distinct_join', $this->rules(Engine::MySql, $sql), $sql);
    }

    public function testCountStarOnABigPostgreSqlTable(): void
    {
        foreach (['SELECT COUNT(*) FROM orders', 'select count(*) from orders;', 'SELECT COUNT(*) AS n FROM orders o', 'SELECT count(*) n FROM orders'] as $sql) {
            $finding = $this->finding(Engine::PostgreSql, $sql, 'count_star_large', $this->orders(2_500_000));
            self::assertNotNull($finding, $sql);
            self::assertStringContainsString('2 500 000', $finding->message);
        }
    }

    /**
     * @return iterable<string, array{Engine, string, ?int}>
     */
    public static function notCountStar(): iterable
    {
        yield 'small table' => [Engine::PostgreSql, 'SELECT COUNT(*) FROM orders', 5_000];
        yield 'just under the threshold' => [Engine::PostgreSql, 'SELECT COUNT(*) FROM orders', 999_999];
        yield 'unknown estimate' => [Engine::PostgreSql, 'SELECT COUNT(*) FROM orders', null];
        yield 'with where' => [Engine::PostgreSql, 'SELECT COUNT(*) FROM orders WHERE qty = 1', 5_000_000];
        yield 'with group by' => [Engine::PostgreSql, 'SELECT COUNT(*) FROM orders GROUP BY qty', 5_000_000];
        yield 'with other columns' => [Engine::PostgreSql, 'SELECT qty, COUNT(*) FROM orders', 5_000_000];
        yield 'count of a column' => [Engine::PostgreSql, 'SELECT COUNT(qty) FROM orders', 5_000_000];
        yield 'mysql' => [Engine::MySql, 'SELECT COUNT(*) FROM orders', 5_000_000];
        yield 'mariadb' => [Engine::MariaDb, 'SELECT COUNT(*) FROM orders', 5_000_000];
        yield 'join' => [Engine::PostgreSql, 'SELECT COUNT(*) FROM orders o JOIN orders p ON p.id = o.id', 5_000_000];
    }

    #[DataProvider('notCountStar')]
    public function testNoCountStarAdvice(Engine $engine, string $sql, ?int $rows): void
    {
        self::assertNotContains('count_star_large', $this->rules($engine, $sql, $this->orders($rows)), $sql);
    }

    public function testCountStarNeedsTheStructure(): void
    {
        self::assertNotContains('count_star_large', $this->rules(Engine::PostgreSql, 'SELECT COUNT(*) FROM orders', null));
    }

    public function testRandomOrderOnABigTable(): void
    {
        self::assertNotNull($this->finding(Engine::MySql, 'SELECT id FROM orders ORDER BY RAND() LIMIT 1', 'random_order', $this->orders(200_000)));
        self::assertNotNull($this->finding(Engine::MariaDb, 'SELECT id FROM orders ORDER BY rand(7) LIMIT 1', 'random_order', $this->orders(200_000)));
        $pg = $this->finding(Engine::PostgreSql, 'SELECT id FROM orders ORDER BY random() LIMIT 5', 'random_order', $this->orders(200_000));
        self::assertNotNull($pg);
        self::assertStringContainsString('200 000', $pg->message);
    }

    /**
     * @return iterable<string, array{Engine, string, ?int}>
     */
    public static function notRandom(): iterable
    {
        yield 'small table' => [Engine::MySql, 'SELECT id FROM orders ORDER BY RAND() LIMIT 1', 500];
        yield 'unknown estimate' => [Engine::MySql, 'SELECT id FROM orders ORDER BY RAND() LIMIT 1', null];
        yield 'with where' => [Engine::MySql, 'SELECT id FROM orders WHERE qty = 1 ORDER BY RAND() LIMIT 1', 200_000];
        yield 'other order' => [Engine::MySql, 'SELECT id FROM orders ORDER BY id LIMIT 1', 200_000];
        yield 'rand is not the first key' => [Engine::MySql, 'SELECT id FROM orders ORDER BY qty, RAND()', 200_000];
        yield 'rand in the select list' => [Engine::MySql, 'SELECT RAND() FROM orders ORDER BY id', 200_000];
        yield 'rand on postgresql does not exist' => [Engine::PostgreSql, 'SELECT id FROM orders ORDER BY RAND()', 200_000];
        yield 'random on mysql does not exist' => [Engine::MySql, 'SELECT id FROM orders ORDER BY random()', 200_000];
        yield 'a column named like it' => [Engine::MySql, 'SELECT id FROM orders ORDER BY rand', 200_000];
        yield 'join' => [Engine::MySql, 'SELECT o.id FROM orders o JOIN orders p ON p.id = o.id ORDER BY RAND()', 200_000];
    }

    #[DataProvider('notRandom')]
    public function testNoRandomOrderAdvice(Engine $engine, string $sql, ?int $rows): void
    {
        self::assertNotContains('random_order', $this->rules($engine, $sql, $this->orders($rows)), $sql);
    }

    public function testClickHouseKeepsOnlyItsPatterns(): void
    {
        $sql = "SELECT DISTINCT o.id FROM orders o JOIN items i ON i.order_id = o.id WHERE o.status = 5 OR o.note = 'x'";

        self::assertSame([], $this->rules(Engine::ClickHouse, $sql, $this->orders(9_000_000)));
    }

    public function testLongHostileInputsStayLinear(): void
    {
        $start = microtime(true);
        $long = 'SELECT DISTINCT o.id FROM orders o JOIN items i ON i.order_id = o.id WHERE '.str_repeat('o.qty = 1 OR ', 1500).'o.note = 2';
        $this->lint(Engine::MySql, $long, $this->orders(1_000_000));
        $this->lint(Engine::PostgreSql, 'SELECT '.str_repeat('o.id, ', 3000).'o.id FROM orders o ORDER BY random()', $this->orders(1_000_000));
        $this->lint(Engine::MySql, 'SELECT DISTINCT '.str_repeat('o.id, ', 3000).'o.id FROM orders o '.str_repeat('JOIN items i ON i.a = o.id ', 1000), $this->orders(1_000_000));

        self::assertLessThan(1.0, microtime(true) - $start);
    }
}
