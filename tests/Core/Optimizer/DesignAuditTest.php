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
}
