<?php

declare(strict_types=1);

namespace App\Tests\Core\Optimizer;

use App\Core\Connection\Engine;
use App\Core\Optimizer\Finding;
use App\Core\Optimizer\FindingRule;
use App\Core\Optimizer\SchemaAuditor;
use App\Core\Optimizer\Severity;
use App\Core\Schema\ColumnDefinition;
use App\Core\Schema\ForeignKeyDefinition;
use App\Core\Schema\IndexDefinition;
use App\Core\Schema\ReferentialAction;
use App\Core\Schema\TableDefinition;
use App\Core\Schema\TableKind;
use PHPUnit\Framework\TestCase;

final class SchemaAuditorTest extends TestCase
{
    /**
     * @param list<string> $columns
     *
     * @return list<ColumnDefinition>
     */
    private function columns(array $columns): array
    {
        return array_map(static fn (string $name): ColumnDefinition => new ColumnDefinition($name, 'integer', false, null, false, null), $columns);
    }

    /**
     * @param list<string>               $columns
     * @param list<IndexDefinition>      $indexes
     * @param list<ForeignKeyDefinition> $foreignKeys
     */
    private function table(string $name, array $columns, array $indexes = [], array $foreignKeys = [], TableKind $kind = TableKind::Table): TableDefinition
    {
        return new TableDefinition($name, $kind, $this->columns($columns), $indexes, $foreignKeys, TableKind::Table === $kind ? null : 'SELECT 1', null);
    }

    /**
     * @param list<string> $columns
     */
    private function fk(string $name, array $columns, string $target = 'other'): ForeignKeyDefinition
    {
        return new ForeignKeyDefinition($name, $columns, $target, array_fill(0, \count($columns), 'id'), ReferentialAction::NoAction, ReferentialAction::NoAction);
    }

    /**
     * @param list<TableDefinition> $tables
     *
     * @return list<Finding>
     */
    private function audit(array $tables, Engine $engine = Engine::PostgreSql): array
    {
        return (new SchemaAuditor())->audit($engine, $tables);
    }

    public function testACleanSchemaHasNoFinding(): void
    {
        $orders = $this->table('orders', ['id', 'customer_id'], [new IndexDefinition('orders_pkey', ['id'], true, true), new IndexDefinition('idx_customer', ['customer_id'], false, false)], [$this->fk('fk_customer', ['customer_id'])]);

        self::assertSame([], $this->audit([$orders]));
    }

    public function testFlagsATableWithoutPrimaryKey(): void
    {
        $findings = $this->audit([$this->table('log', ['message'])]);

        self::assertCount(1, $findings);
        self::assertSame(FindingRule::MissingPrimaryKey, $findings[0]->rule);
        self::assertSame(Severity::Warning, $findings[0]->severity);
        self::assertSame('log', $findings[0]->table);
        self::assertNull($findings[0]->suggestion);
    }

    public function testViewsAreNotAudited(): void
    {
        self::assertSame([], $this->audit([$this->table('v_totals', ['a'], kind: TableKind::View)]));
    }

    public function testFlagsAForeignKeyWithoutIndexAndSuggestsOne(): void
    {
        $orders = $this->table('orders', ['id', 'customer_id'], [new IndexDefinition('orders_pkey', ['id'], true, true)], [$this->fk('fk_customer', ['customer_id'])]);

        $findings = $this->audit([$orders]);

        self::assertCount(1, $findings);
        self::assertSame(FindingRule::ForeignKeyWithoutIndex, $findings[0]->rule);
        self::assertSame(Severity::Warning, $findings[0]->severity);
        self::assertSame(['customer_id'], $findings[0]->columns);
        self::assertSame('CREATE INDEX idx_orders_customer_id ON "orders" ("customer_id");', $findings[0]->suggestion);
    }

    public function testSuggestsInTheDialectOfTheEngine(): void
    {
        $orders = $this->table('orders', ['id', 'customer_id'], [new IndexDefinition('PRIMARY', ['id'], true, true)], [$this->fk('fk_customer', ['customer_id'])]);

        self::assertSame('CREATE INDEX idx_orders_customer_id ON `orders` (`customer_id`);', $this->audit([$orders], Engine::MySql)[0]->suggestion);
        self::assertSame('CREATE INDEX idx_orders_customer_id ON `orders` (`customer_id`);', $this->audit([$orders], Engine::MariaDb)[0]->suggestion);
    }

    public function testQuotesAwkwardNames(): void
    {
        $table = $this->table('we"ird', ['id', 'a"b'], [new IndexDefinition('pk', ['id'], true, true)], [$this->fk('fk', ['a"b'])]);

        self::assertStringContainsString('"we""ird" ("a""b")', (string) $this->audit([$table])[0]->suggestion);
    }

    public function testAnIndexLeadingWithTheKeyColumnsCoversTheKey(): void
    {
        $orders = $this->table('orders', ['id', 'customer_id', 'ordered_at'], [
            new IndexDefinition('orders_pkey', ['id'], true, true),
            new IndexDefinition('idx_customer_date', ['customer_id', 'ordered_at'], false, false),
        ], [$this->fk('fk_customer', ['customer_id'])]);

        self::assertSame([], $this->audit([$orders]));
    }

    public function testAnIndexWhereTheKeyIsNotFirstDoesNotCoverIt(): void
    {
        $orders = $this->table('orders', ['id', 'customer_id', 'ordered_at'], [
            new IndexDefinition('orders_pkey', ['id'], true, true),
            new IndexDefinition('idx_date_customer', ['ordered_at', 'customer_id'], false, false),
        ], [$this->fk('fk_customer', ['customer_id'])]);

        self::assertSame(FindingRule::ForeignKeyWithoutIndex, $this->audit([$orders])[0]->rule);
    }

    public function testThePrimaryKeyCanCoverAForeignKey(): void
    {
        $link = $this->table('link', ['a_id', 'b_id'], [new IndexDefinition('link_pkey', ['a_id', 'b_id'], true, true)], [$this->fk('fk_a', ['a_id']), $this->fk('fk_b', ['b_id'])]);

        $findings = $this->audit([$link]);

        self::assertCount(1, $findings, 'a_id est couvert par la clé primaire, pas b_id');
        self::assertSame(['b_id'], $findings[0]->columns);
    }

    public function testACompositeKeyIsCoveredWhateverTheOrderOfItsColumns(): void
    {
        $table = $this->table('t', ['id', 'a', 'b'], [new IndexDefinition('pk', ['id'], true, true), new IndexDefinition('idx_ba', ['b', 'a'], false, false)], [$this->fk('fk_ab', ['a', 'b'])]);

        self::assertSame([], $this->audit([$table]));
    }

    public function testFlagsAnIndexThatIsAPrefixOfAnother(): void
    {
        $table = $this->table('orders', ['id', 'customer_id', 'ordered_at'], [
            new IndexDefinition('orders_pkey', ['id'], true, true),
            new IndexDefinition('idx_customer', ['customer_id'], false, false),
            new IndexDefinition('idx_customer_date', ['customer_id', 'ordered_at'], false, false),
        ]);

        $findings = $this->audit([$table]);

        self::assertCount(1, $findings);
        self::assertSame(FindingRule::RedundantIndex, $findings[0]->rule);
        self::assertSame(Severity::Info, $findings[0]->severity);
        self::assertSame('idx_customer', $findings[0]->index);
        self::assertSame('idx_customer_date', $findings[0]->coveredBy);
        self::assertSame('DROP INDEX "idx_customer";', $findings[0]->suggestion);
    }

    public function testDropsAnIndexTheMySqlWay(): void
    {
        $table = $this->table('orders', ['id', 'a', 'b'], [
            new IndexDefinition('PRIMARY', ['id'], true, true),
            new IndexDefinition('idx_a', ['a'], false, false),
            new IndexDefinition('idx_ab', ['a', 'b'], false, false),
        ]);

        self::assertSame('ALTER TABLE `orders` DROP INDEX `idx_a`;', $this->audit([$table], Engine::MySql)[0]->suggestion);
    }

    public function testAUniqueIndexIsNeverRedundantBecauseItEnforcesSomething(): void
    {
        $table = $this->table('t', ['id', 'email', 'name'], [
            new IndexDefinition('pk', ['id'], true, true),
            new IndexDefinition('uq_email', ['email'], true, false),
            new IndexDefinition('idx_email_name', ['email', 'name'], false, false),
        ]);

        self::assertSame([], $this->audit([$table]));
    }

    public function testAPlainIndexDuplicatingAUniqueOneIsRedundant(): void
    {
        $table = $this->table('t', ['id', 'email'], [
            new IndexDefinition('pk', ['id'], true, true),
            new IndexDefinition('uq_email', ['email'], true, false),
            new IndexDefinition('idx_email', ['email'], false, false),
        ]);

        $findings = $this->audit([$table]);

        self::assertCount(1, $findings);
        self::assertSame('idx_email', $findings[0]->index);
        self::assertSame('uq_email', $findings[0]->coveredBy);
    }

    public function testTwoIdenticalPlainIndexesFlagOnlyOne(): void
    {
        $table = $this->table('t', ['id', 'a'], [
            new IndexDefinition('pk', ['id'], true, true),
            new IndexDefinition('idx_a1', ['a'], false, false),
            new IndexDefinition('idx_a2', ['a'], false, false),
        ]);

        $findings = $this->audit([$table]);

        self::assertCount(1, $findings);
        self::assertSame('idx_a2', $findings[0]->index);
        self::assertSame('idx_a1', $findings[0]->coveredBy);
    }

    public function testAnIndexThatMerelySharesAStartingColumnWithAUniqueOneStillCounts(): void
    {
        $table = $this->table('t', ['id', 'a', 'b'], [
            new IndexDefinition('pk', ['id'], true, true),
            new IndexDefinition('uq_ab', ['a', 'b'], true, false),
            new IndexDefinition('idx_a', ['a'], false, false),
        ]);

        self::assertSame('idx_a', $this->audit([$table])[0]->index);
    }

    public function testLongGeneratedIndexNamesStayWithinEngineLimits(): void
    {
        $long = str_repeat('x', 50);
        $table = $this->table($long, ['id', $long.'_id'], [new IndexDefinition('pk', ['id'], true, true)], [$this->fk('fk', [$long.'_id'])]);

        $suggestion = (string) $this->audit([$table])[0]->suggestion;

        self::assertSame(1, preg_match('/^CREATE INDEX (\S+) ON /', $suggestion, $matches));
        self::assertLessThanOrEqual(63, \strlen($matches[1] ?? ''));
    }

    public function testFindingsAreOrderedByTableThenSeverity(): void
    {
        $b = $this->table('b', ['x']);
        $a = $this->table('a', ['id', 'ref'], [new IndexDefinition('pk', ['id'], true, true)], [$this->fk('fk', ['ref'])]);

        $findings = $this->audit([$b, $a]);

        self::assertSame(['a', 'b'], array_map(static fn (Finding $f): string => $f->table, $findings));
    }

    public function testWithinATableWarningsComeBeforeInfos(): void
    {
        $table = $this->table('t', ['a', 'b'], [new IndexDefinition('idx_a', ['a'], false, false), new IndexDefinition('idx_ab', ['a', 'b'], false, false)]);

        $findings = $this->audit([$table]);

        self::assertSame([Severity::Warning, Severity::Info], array_map(static fn (Finding $f): Severity => $f->severity, $findings));
    }
}
