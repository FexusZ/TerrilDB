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
 * Le linter croisé avec la structure de la table : avec une table connue, « l'index ne servira pas » n'est dit que d'une colonne
 * réellement indexée en tête ; sans table connue le conditionnel reste ; une colonne sans index ne donne rien.
 */
final class QueryLinterSchemaTest extends TestCase
{
    /** @var list<string> */
    private array $asked = [];

    /**
     * @param list<IndexDefinition> $indexes
     */
    private function orders(array $indexes = [], TableKind $kind = TableKind::Table, ?int $rows = null): TableDefinition
    {
        $columns = [];
        foreach (['id' => 'integer', 'status' => 'varchar(20)', 'ordered_at' => 'timestamp', 'shipped_at' => 'timestamp', 'email' => 'varchar(80)', 'note' => 'text', 'qty' => 'integer'] as $name => $type) {
            $columns[] = new ColumnDefinition($name, $type, true, null, false, null);
        }

        return new TableDefinition('orders', $kind, $columns, $indexes, [], TableKind::Table === $kind ? null : 'select 1', $rows);
    }

    /**
     * @return list<IndexDefinition>
     */
    private static function indexes(): array
    {
        return [
            new IndexDefinition('orders_pkey', ['id'], true, true),
            new IndexDefinition('idx_ordered', ['ordered_at'], false, false),
            new IndexDefinition('idx_status_shipped', ['status', 'shipped_at'], false, false),
            new IndexDefinition('idx_email_lower', ['lower(email)'], false, false),
            new IndexDefinition('idx_qty', ['qty'], false, false),
        ];
    }

    /**
     * @return list<LintFinding>
     */
    private function lint(Engine $engine, string $sql, ?TableDefinition $table, ?\Throwable $failure = null): array
    {
        $this->asked = [];

        return (new QueryLinter())->lint($engine, $sql, function (string $name) use ($table, $failure): ?TableDefinition {
            $this->asked[] = $name;
            if (null !== $failure) {
                throw $failure;
            }

            return $table;
        });
    }

    /**
     * @param list<LintFinding> $findings
     */
    private static function find(array $findings, string $rule): ?LintFinding
    {
        foreach ($findings as $finding) {
            if ($finding->rule->value === $rule) {
                return $finding;
            }
        }

        return null;
    }

    public function testAnIndexedColumnGetsTheStrongTextWithTheIndexName(): void
    {
        $finding = self::find($this->lint(Engine::MySql, 'SELECT id FROM orders WHERE YEAR(ordered_at) = 2026', $this->orders(self::indexes())), 'function_on_column');

        self::assertNotNull($finding);
        self::assertStringContainsString('idx_ordered', $finding->message);
        self::assertStringContainsString('ne pourra pas servir', $finding->message);
        self::assertSame(['ordered_at'], $finding->columns);
        self::assertSame(['orders'], $this->asked);
    }

    public function testACompositeIndexServesItsLeadingColumnOnly(): void
    {
        $leading = self::find($this->lint(Engine::PostgreSql, "SELECT id FROM orders WHERE LOWER(status) = 'x'", $this->orders(self::indexes())), 'function_on_column');
        $second = self::find($this->lint(Engine::PostgreSql, 'SELECT id FROM orders WHERE YEAR(shipped_at) = 2026', $this->orders(self::indexes())), 'function_on_column');

        self::assertNotNull($leading);
        self::assertStringContainsString('idx_status_shipped', $leading->message);
        self::assertNull($second);
    }

    public function testAColumnWithoutIndexSaysNothing(): void
    {
        $sql = "SELECT id FROM orders WHERE UPPER(note) = 'X' AND qty + 1 = 3 AND status LIKE '%x'";
        $table = $this->orders([new IndexDefinition('orders_pkey', ['id'], true, true)]);

        self::assertSame([], array_map(static fn (LintFinding $f): string => $f->rule->value, $this->lint(Engine::MySql, $sql, $table)));
    }

    public function testAnExpressionIndexOnTheColumnSilencesTheRule(): void
    {
        $finding = self::find($this->lint(Engine::PostgreSql, "SELECT id FROM orders WHERE LOWER(email) = 'a@b.c'", $this->orders(self::indexes())), 'function_on_column');

        self::assertNull($finding);
    }

    public function testAnUnknownColumnSaysNothing(): void
    {
        self::assertNull(self::find($this->lint(Engine::MySql, 'SELECT id FROM orders WHERE YEAR(nope) = 2026', $this->orders(self::indexes())), 'function_on_column'));
    }

    public function testTheAliasIsUnderstoodAndAForeignQualifierIsNot(): void
    {
        $aliased = self::find($this->lint(Engine::MySql, 'SELECT o.id FROM orders AS o WHERE YEAR(o.ordered_at) = 2026', $this->orders(self::indexes())), 'function_on_column');
        $bare = self::find($this->lint(Engine::MySql, 'SELECT o.id FROM orders o WHERE YEAR(o.ordered_at) = 2026', $this->orders(self::indexes())), 'function_on_column');
        $foreign = self::find($this->lint(Engine::MySql, 'SELECT o.id FROM orders o WHERE YEAR(x.ordered_at) = 2026', $this->orders(self::indexes())), 'function_on_column');
        $byName = self::find($this->lint(Engine::MySql, 'SELECT id FROM orders WHERE YEAR(orders.ordered_at) = 2026', $this->orders(self::indexes())), 'function_on_column');

        self::assertStringContainsString('idx_ordered', (string) $aliased?->message);
        self::assertStringContainsString('idx_ordered', (string) $bare?->message);
        self::assertNull($foreign);
        self::assertStringContainsString('idx_ordered', (string) $byName?->message);
    }

    public function testArithmeticFollowsTheSameRule(): void
    {
        $indexed = self::find($this->lint(Engine::MySql, 'SELECT id FROM orders WHERE qty + 1 = 3', $this->orders(self::indexes())), 'arithmetic_on_column');
        $plain = self::find($this->lint(Engine::MySql, 'SELECT id FROM orders WHERE note + 1 = 3', $this->orders(self::indexes())), 'arithmetic_on_column');

        self::assertStringContainsString('idx_qty', (string) $indexed?->message);
        self::assertNull($plain);
    }

    public function testALeadingWildcardOnAnIndexedColumn(): void
    {
        $mysql = self::find($this->lint(Engine::MySql, "SELECT id FROM orders WHERE status LIKE '%x'", $this->orders(self::indexes())), 'leading_wildcard_like');
        $plain = self::find($this->lint(Engine::MySql, "SELECT id FROM orders WHERE note LIKE '%x'", $this->orders(self::indexes())), 'leading_wildcard_like');

        self::assertStringContainsString('idx_status_shipped', (string) $mysql?->message);
        self::assertNull($plain);
    }

    public function testOnPostgreSqlATrigramIndexCouldServeSoTheTextSaysSo(): void
    {
        $finding = self::find($this->lint(Engine::PostgreSql, "SELECT id FROM orders WHERE status LIKE '%x'", $this->orders(self::indexes())), 'leading_wildcard_like');

        self::assertNotNull($finding);
        self::assertStringContainsString('pg_trgm', $finding->message);
        self::assertStringNotContainsString('ne pourra pas servir', $finding->message);
    }

    public function testWithoutASchemaTheConditionalTextStays(): void
    {
        $finding = self::find((new QueryLinter())->lint(Engine::MySql, 'SELECT id FROM orders WHERE YEAR(ordered_at) = 2026'), 'function_on_column');

        self::assertNotNull($finding);
        self::assertStringContainsString('un index sur cette colonne ne peut pas servir', $finding->message);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function notASimpleTable(): iterable
    {
        yield 'join' => ['SELECT o.id FROM orders o JOIN users u ON u.id = o.id WHERE YEAR(o.ordered_at) = 2026'];
        yield 'comma join' => ['SELECT o.id FROM orders o, users u WHERE YEAR(o.ordered_at) = 2026'];
        yield 'subquery' => ['SELECT id FROM orders WHERE id IN (SELECT id FROM orders WHERE YEAR(ordered_at) = 2026)'];
        yield 'derived table' => ['SELECT id FROM (SELECT id, ordered_at FROM orders) d WHERE YEAR(ordered_at) = 2026'];
        yield 'cte' => ['WITH a AS (SELECT id, ordered_at FROM orders) SELECT id FROM a WHERE YEAR(ordered_at) = 2026'];
        yield 'schema qualified' => ['SELECT id FROM shop.orders WHERE YEAR(ordered_at) = 2026'];
        yield 'union' => ['SELECT id FROM orders WHERE YEAR(ordered_at) = 2026 UNION SELECT id FROM orders'];
    }

    #[DataProvider('notASimpleTable')]
    public function testOnlyASingleTableIsLookedUpOtherwiseTheConditionalTextStays(string $sql): void
    {
        $findings = $this->lint(Engine::MySql, $sql, $this->orders(self::indexes()));
        $finding = self::find($findings, 'function_on_column');

        self::assertSame([], $this->asked, 'the introspection must not be called');
        self::assertNotNull($finding, $sql);
        self::assertStringContainsString('un index sur cette colonne ne peut pas servir', $finding->message);
    }

    public function testTheTableIsReadOnceWhateverTheNumberOfConditions(): void
    {
        $this->lint(Engine::MySql, "SELECT id FROM orders WHERE YEAR(ordered_at) = 2026 AND qty + 1 = 3 AND status LIKE '%x' AND LOWER(status) = 'a'", $this->orders(self::indexes()));

        self::assertSame(['orders'], $this->asked);
    }

    public function testNothingIsReadWhenNoRuleNeedsTheTable(): void
    {
        $this->lint(Engine::MySql, 'SELECT * FROM orders WHERE id = 1', $this->orders(self::indexes()));
        $this->lint(Engine::ClickHouse, 'SELECT id FROM orders WHERE YEAR(ordered_at) = 2026', $this->orders(self::indexes()));

        self::assertSame([], $this->asked);
    }

    public function testAnUnreadableTableKeepsTheConditionalTextAndNeverThrows(): void
    {
        foreach ([null, new \RuntimeException('boom')] as $failure) {
            $table = null === $failure ? null : $this->orders();
            $finding = self::find($this->lint(Engine::MySql, 'SELECT id FROM orders WHERE YEAR(ordered_at) = 2026', $table, $failure), 'function_on_column');

            self::assertNotNull($finding);
            self::assertStringContainsString('un index sur cette colonne ne peut pas servir', $finding->message);
        }
    }

    public function testAViewIsNotJudgedByItsIndexes(): void
    {
        $view = new TableDefinition('orders', TableKind::View, $this->orders()->columns, [], [], 'select 1', null);
        $finding = self::find($this->lint(Engine::MySql, 'SELECT id FROM orders WHERE YEAR(ordered_at) = 2026', $view), 'function_on_column');

        self::assertNotNull($finding);
        self::assertStringContainsString('un index sur cette colonne ne peut pas servir', $finding->message);
    }
}
