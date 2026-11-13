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
     * @param list<ForeignKeyDefinition> $foreignKeys
     */
    private static function ordersWith(ColumnDefinition $column, array $foreignKeys): TableDefinition
    {
        return self::table('orders', [self::column('id', 'integer'), $column], [self::primary('id')], $foreignKeys);
    }

    /**
     * @return iterable<string, array{TableDefinition, TableDefinition}>
     */
    public static function notImplicitKeys(): iterable
    {
        $customers = self::table('customers', [self::column('id', 'integer')], [self::primary('id')]);
        $orders = static fn (ColumnDefinition $column, ForeignKeyDefinition ...$foreignKeys): TableDefinition => self::ordersWith($column, array_values($foreignKeys));
        $declared = new ForeignKeyDefinition('fk', ['customer_id'], 'customers', ['id'], ReferentialAction::NoAction, ReferentialAction::NoAction);

        yield 'key declared' => [$orders(self::column('customer_id', 'integer'), $declared), $customers];
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

    public function testFlagsTwoImplicitlyJoinedTextColumnsWithDifferentCollations(): void
    {
        $users = self::table('users', [self::column('id', 'varchar(10)', 'utf8mb4', 'utf8mb4_bin')], [self::primary('id')]);
        $posts = self::table('posts', [
            self::column('id', 'int'),
            self::column('user_id', 'varchar(10)', 'utf8mb4', 'utf8mb4_general_ci'),
        ], [self::primary('id')]);

        $findings = self::rule(FindingRule::CollationMismatch, [$posts, $users]);

        self::assertCount(1, $findings);
        self::assertSame(Severity::Warning, $findings[0]->severity);
        self::assertSame('posts', $findings[0]->table);
        self::assertSame(['user_id'], $findings[0]->columns);
        self::assertSame('utf8mb4_general_ci ≠ utf8mb4_bin (users.id)', $findings[0]->detail);
        self::assertNull($findings[0]->suggestion);
    }

    public function testSaysNothingWhenCollationsAgreeOrAreUnknown(): void
    {
        $users = self::table('users', [self::column('id', 'varchar(10)', 'utf8mb4', 'utf8mb4_bin')], [self::primary('id')]);
        $same = self::table('posts', [self::column('user_id', 'varchar(10)', 'utf8mb4', 'utf8mb4_bin')]);
        $unknown = self::table('notes', [self::column('user_id', 'varchar(10)')]);

        self::assertSame([], self::rule(FindingRule::CollationMismatch, [$same, $unknown, $users]));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function backupSuffixes(): iterable
    {
        yield '_old' => ['_old'];
        yield '_bak' => ['_bak'];
        yield '_backup' => ['_backup'];
        yield '_copy' => ['_copy'];
        yield 'upper case' => ['_OLD'];
    }

    #[DataProvider('backupSuffixes')]
    public function testFlagsABackupCopyOfANeighbourTable(string $suffix): void
    {
        $columns = [self::column('id', 'int'), self::column('label', 'varchar(20)', 'utf8mb4', 'utf8mb4_bin')];
        $orders = self::table('orders', $columns, [self::primary('id')]);
        $copy = self::table('orders'.$suffix, $columns, [self::primary('id')]);

        $findings = self::rule(FindingRule::TwinTable, [$orders, $copy]);

        self::assertCount(1, $findings);
        self::assertSame(Severity::Info, $findings[0]->severity);
        self::assertSame('orders'.$suffix, $findings[0]->table);
        self::assertSame('orders', $findings[0]->detail);
        self::assertNull($findings[0]->suggestion);
        self::assertSame([], self::rule(FindingRule::CopyWithoutIndexes, [$orders, $copy]));
    }

    public function testACopyThatLostEveryIndexIsFlaggedAsSuch(): void
    {
        $columns = [self::column('id', 'int'), self::column('label', 'varchar(20)')];
        $orders = self::table('orders', $columns, [self::primary('id'), new IndexDefinition('idx_label', ['label'], false, false)]);
        $copy = self::table('orders_bak', $columns);

        $findings = self::rule(FindingRule::CopyWithoutIndexes, [$orders, $copy]);

        self::assertCount(1, $findings);
        self::assertSame('orders_bak', $findings[0]->table);
        self::assertSame('orders', $findings[0]->detail);
        self::assertCount(1, self::rule(FindingRule::TwinTable, [$orders, $copy]));
    }

    public function testSaysNothingWhenTheNeighbourIsNotTheSameShape(): void
    {
        $orders = self::table('orders', [self::column('id', 'int'), self::column('label', 'text')], [self::primary('id')]);
        $otherColumns = self::table('orders_old', [self::column('id', 'int'), self::column('note', 'text')], [self::primary('id')]);
        $otherTypes = self::table('orders_bak', [self::column('id', 'bigint'), self::column('label', 'text')], [self::primary('id')]);
        $otherOrder = self::table('orders_copy', [self::column('label', 'text'), self::column('id', 'int')], [self::primary('id')]);
        $noNeighbour = self::table('invoices_old', [self::column('id', 'int')], [self::primary('id')]);
        $notASuffix = self::table('older', [self::column('id', 'int'), self::column('label', 'text')]);

        self::assertSame([], self::rule(FindingRule::TwinTable, [$orders, $otherColumns, $otherTypes, $otherOrder, $noNeighbour, $notASuffix]));
        self::assertSame([], self::rule(FindingRule::CopyWithoutIndexes, [$orders, $otherColumns, $otherTypes, $otherOrder, $noNeighbour, $notASuffix]));
    }

    public function testTwoIndexlessTablesAreNotACopyWithoutIndexes(): void
    {
        $columns = [self::column('id', 'int')];

        self::assertSame([], self::rule(FindingRule::CopyWithoutIndexes, [self::table('logs', $columns), self::table('logs_old', $columns)]));
        self::assertCount(1, self::rule(FindingRule::TwinTable, [self::table('logs', $columns), self::table('logs_old', $columns)]));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function deadlineNames(): iterable
    {
        foreach (['expires_at', 'expiry', 'expiration_date', 'valid_until', 'valid_to', 'ends_at', 'end_date', 'due_date', 'deadline', 'renew_at', 'EXPIRES_AT'] as $name) {
            yield $name => [$name];
        }
    }

    #[DataProvider('deadlineNames')]
    public function testFlagsATimestampThatHoldsADistantDeadlineOnMySql(string $name): void
    {
        $table = self::table('licences', [self::column('id'), self::column($name, 'timestamp')], [self::primary('id')]);

        $findings = self::rule(FindingRule::TimestampDeadline, [$table]);

        self::assertCount(1, $findings);
        self::assertSame(Severity::Info, $findings[0]->severity);
        self::assertSame([$name], $findings[0]->columns);
        self::assertSame('timestamp', $findings[0]->detail);
        self::assertNull($findings[0]->suggestion);
    }

    public function testTimestampsWithAFractionAreFlaggedToo(): void
    {
        $table = self::table('licences', [self::column('expires_at', 'timestamp(3)')]);

        self::assertCount(1, self::rule(FindingRule::TimestampDeadline, [$table]));
    }

    public function testSaysNothingForOtherTypesNamesOrEngines(): void
    {
        $table = self::table('licences', [
            self::column('expires_at', 'datetime'),
            self::column('created_at', 'timestamp'),
            self::column('expiry_policy', 'varchar(20)'),
            self::column('suspended_until_review', 'int'),
        ]);

        self::assertSame([], self::rule(FindingRule::TimestampDeadline, [$table]));
        // MariaDB 11.5+ étend TIMESTAMP à 2106 ; PostgreSQL n'a pas cette limite.
        $risky = self::table('licences', [self::column('expires_at', 'timestamp')]);
        self::assertSame([], self::rule(FindingRule::TimestampDeadline, [$risky], Engine::MariaDb));
        self::assertSame([], self::rule(FindingRule::TimestampDeadline, [self::table('l', [self::column('expires_at', 'timestamp without time zone')])], Engine::PostgreSql));
    }
}
