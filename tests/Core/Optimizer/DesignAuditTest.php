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
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Règles de conception de l'audit (jeux de caractères, collations, relations implicites, tables jumelles, types).
 * Chaque règle a ses cas qui ne doivent RIEN dire : dans le doute, l'audit se tait.
 */
final class DesignAuditTest extends TestCase
{
    private static function column(string $name, string $type = 'int', ?string $characterSet = null, ?string $collation = null): ColumnDefinition
    {
        return new ColumnDefinition($name, $type, false, null, false, null, $characterSet, $collation);
    }

    /**
     * @param list<ColumnDefinition>     $columns
     * @param list<IndexDefinition>      $indexes
     * @param list<ForeignKeyDefinition> $foreignKeys
     */
    private static function table(string $name, array $columns, array $indexes = [], array $foreignKeys = []): TableDefinition
    {
        return new TableDefinition($name, TableKind::Table, $columns, $indexes, $foreignKeys, null, null);
    }

    private static function primary(string ...$columns): IndexDefinition
    {
        return new IndexDefinition('PRIMARY', array_values($columns), true, true);
    }

    /**
     * @param list<TableDefinition> $tables
     *
     * @return list<Finding>
     */
    private static function rule(FindingRule $rule, array $tables, Engine $engine = Engine::MySql): array
    {
        return array_values(array_filter(
            (new SchemaAuditor())->audit($engine, $tables),
            static fn (Finding $f): bool => $f->rule === $rule,
        ));
    }

    /**
     * @return iterable<string, array{Engine, string}>
     */
    public static function legacyCharsets(): iterable
    {
        yield 'MySQL utf8mb3' => [Engine::MySql, 'utf8mb3'];
        yield 'MariaDB utf8mb3' => [Engine::MariaDb, 'utf8mb3'];
        yield 'alias utf8' => [Engine::MySql, 'utf8'];
    }

    #[DataProvider('legacyCharsets')]
    public function testFlagsTextColumnsInTheLegacyThreeByteCharacterSet(Engine $engine, string $charset): void
    {
        $table = self::table('users', [
            self::column('id'),
            self::column('name', 'varchar(80)', $charset, $charset.'_general_ci'),
            self::column('bio', 'text', $charset, $charset.'_bin'),
            self::column('city', 'varchar(40)', 'utf8mb4', 'utf8mb4_general_ci'),
        ], [self::primary('id')]);

        $findings = self::rule(FindingRule::LegacyCharacterSet, [$table], $engine);

        self::assertCount(1, $findings);
        self::assertSame(Severity::Warning, $findings[0]->severity);
        self::assertSame('users', $findings[0]->table);
        self::assertSame(['name', 'bio'], $findings[0]->columns);
        self::assertSame('ALTER TABLE `users` CONVERT TO CHARACTER SET utf8mb4;', $findings[0]->suggestion);
    }

    public function testSaysNothingWhenEveryTextColumnIsUtf8mb4OrUnknown(): void
    {
        $table = self::table('users', [
            self::column('id'),
            self::column('name', 'varchar(80)', 'utf8mb4', 'utf8mb4_0900_ai_ci'),
            self::column('raw', 'varbinary(8)'),
            self::column('latin', 'varchar(8)', 'latin1', 'latin1_swedish_ci'),
        ], [self::primary('id')]);

        self::assertSame([], self::rule(FindingRule::LegacyCharacterSet, [$table]));
        self::assertSame([], self::rule(FindingRule::LegacyCharacterSet, [self::table('t', [self::column('a', 'text', 'utf8mb3')])], Engine::PostgreSql));
    }

    public function testNoSuggestionForATableNameThatCouldBreakOutOfTheStatement(): void
    {
        $table = self::table("a\nb", [self::column('n', 'text', 'utf8mb3')]);

        $findings = self::rule(FindingRule::LegacyCharacterSet, [$table]);

        self::assertCount(1, $findings);
        self::assertNull($findings[0]->suggestion);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function pluralForms(): iterable
    {
        yield 'same name' => ['customer'];
        yield 'plural s' => ['customers'];
        yield 'plural es' => ['addresses'];
        yield 'plural ies' => ['categories'];
    }

    #[DataProvider('pluralForms')]
    public function testFlagsAnIdColumnThatNamesATableWithoutDeclaringTheKey(string $targetName): void
    {
        $stem = match ($targetName) {
            'addresses' => 'address',
            'categories' => 'category',
            default => 'customer',
        };
        $target = self::table($targetName, [self::column('id', 'integer')], [self::primary('id')]);
        $orders = self::table('orders', [self::column('id', 'integer'), self::column($stem.'_id', 'integer')], [self::primary('id')]);

        $findings = self::rule(FindingRule::ImplicitForeignKey, [$orders, $target], Engine::PostgreSql);

        self::assertCount(1, $findings);
        self::assertSame(Severity::Info, $findings[0]->severity);
        self::assertSame('orders', $findings[0]->table);
        self::assertSame([$stem.'_id'], $findings[0]->columns);
        self::assertSame($targetName.'(id)', $findings[0]->detail);
        self::assertSame(\sprintf('ALTER TABLE "orders" ADD FOREIGN KEY ("%s_id") REFERENCES "%s" ("id");', $stem, $targetName), $findings[0]->suggestion);
    }

    public function testTheImplicitKeySuggestionUsesBackticksOnMySql(): void
    {
        $target = self::table('customers', [self::column('id', 'int unsigned')], [self::primary('id')]);
        $orders = self::table('orders', [self::column('id', 'int unsigned'), self::column('customer_id', 'int unsigned')], [self::primary('id')]);

        $findings = self::rule(FindingRule::ImplicitForeignKey, [$orders, $target]);

        self::assertSame('ALTER TABLE `orders` ADD FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`);', $findings[0]->suggestion ?? null);
    }

    /**
     * @return iterable<string, array{TableDefinition, TableDefinition}>
     */
    public static function notImplicitKeys(): iterable
    {
        $customers = self::table('customers', [self::column('id', 'integer')], [self::primary('id')]);
        /** @param list<ForeignKeyDefinition> $foreignKeys */
        $orders = static fn (ColumnDefinition $column, array $foreignKeys = []): TableDefinition => self::table('orders', [self::column('id', 'integer'), $column], [self::primary('id')], $foreignKeys);
        $declared = new ForeignKeyDefinition('fk', ['customer_id'], 'customers', ['id'], ReferentialAction::NoAction, ReferentialAction::NoAction);

        yield 'key declared' => [$orders(self::column('customer_id', 'integer'), [$declared]), $customers];
        yield 'other integer width' => [$orders(self::column('customer_id', 'bigint')), $customers];
        yield 'text against integer' => [$orders(self::column('customer_id', 'text')), $customers];
        yield 'no such table' => [$orders(self::column('vendor_id', 'integer')), $customers];
        yield 'not an id column' => [$orders(self::column('customer_ref', 'integer')), $customers];
        yield 'bare id' => [$orders(self::column('_id', 'integer')), $customers];
        yield 'target key is not an id' => [$orders(self::column('customer_id', 'integer')), self::table('customers', [self::column('id', 'integer'), self::column('code', 'integer')], [self::primary('code')])];
        yield 'composite target key' => [$orders(self::column('customer_id', 'integer')), self::table('customers', [self::column('id', 'integer'), self::column('shop', 'integer')], [self::primary('id', 'shop')])];
        yield 'target without key' => [$orders(self::column('customer_id', 'integer')), self::table('customers', [self::column('id', 'integer')])];
        yield 'own identity' => [self::table('orders', [self::column('customer_id', 'integer')], [self::primary('customer_id')]), $customers];
    }

    #[DataProvider('notImplicitKeys')]
    public function testSaysNothingWhenTheRelationIsNotCertain(TableDefinition $orders, TableDefinition $customers): void
    {
        self::assertSame([], self::rule(FindingRule::ImplicitForeignKey, [$orders, $customers], Engine::PostgreSql));
    }

    public function testSaysNothingWhenTwoTablesMatchTheName(): void
    {
        $one = self::table('user', [self::column('id')], [self::primary('id')]);
        $many = self::table('users', [self::column('id')], [self::primary('id')]);
        $post = self::table('post', [self::column('id'), self::column('user_id')], [self::primary('id')]);

        self::assertSame([], self::rule(FindingRule::ImplicitForeignKey, [$post, $one, $many]));
    }

    public function testAJoinTableKeyColumnIsStillJudged(): void
    {
        $products = self::table('products', [self::column('id')], [self::primary('id')]);
        $links = self::table('product_tags', [self::column('product_id'), self::column('tag_id')], [self::primary('product_id', 'tag_id')]);

        $findings = self::rule(FindingRule::ImplicitForeignKey, [$links, $products]);

        self::assertCount(1, $findings);
        self::assertSame(['product_id'], $findings[0]->columns);
    }
}
