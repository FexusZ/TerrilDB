<?php

declare(strict_types=1);

namespace App\Tests\Core\Optimizer;

use App\Core\Connection\Engine;
use App\Core\Optimizer\Finding;
use App\Core\Optimizer\SchemaAuditor;
use App\Core\Schema\AutoCounter;
use App\Core\Schema\ColumnDefinition;
use App\Core\Schema\IndexDefinition;
use App\Core\Schema\IndexUsage;
use App\Core\Schema\IndexUsageReport;
use App\Core\Schema\TableDefinition;
use App\Core\Schema\TableKind;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Les règles qui proposent du SQL (index inutilisé, compteur, UUID en texte, montant flottant) ne citent jamais un nom hostile.
 */
final class HostileNamesInSuggestionsTest extends TestCase
{
    /**
     * @return iterable<string, array{string, Engine}>
     */
    public static function cases(): iterable
    {
        foreach ([Engine::PostgreSql, Engine::MySql, Engine::MariaDb] as $engine) {
            yield "LF {$engine->value}" => ["x\nDROP DATABASE y", $engine];
            yield "NEL {$engine->value}" => ["x\u{85}DROP", $engine];
            yield "LS {$engine->value}" => ["x\u{2028}DROP", $engine];
        }
    }

    /**
     * @param list<Finding> $findings
     */
    private function assertNoSuggestion(array $findings): void
    {
        self::assertNotSame([], $findings);
        foreach ($findings as $finding) {
            self::assertNull($finding->suggestion);
        }
    }

    #[DataProvider('cases')]
    public function testUnusedIndexSuggestionRefusesAHostileName(string $name, Engine $engine): void
    {
        $table = new TableDefinition('t', TableKind::Table, [new ColumnDefinition('id', 'integer', false, null, false, null), new ColumnDefinition('a', 'integer', false, null, false, null)], [
            new IndexDefinition('PRIMARY', ['id'], true, true),
            new IndexDefinition($name, ['a'], false, false),
        ], [], null, null);
        $usage = IndexUsageReport::available(7 * 86400, [new IndexUsage('t', 'PRIMARY', 0), new IndexUsage('t', $name, 0)]);

        $findings = array_values(array_filter((new SchemaAuditor())->audit($engine, [$table], usage: $usage), static fn (Finding $f): bool => $f->index === $name));

        $this->assertNoSuggestion($findings);
    }

    #[DataProvider('cases')]
    public function testCounterSuggestionRefusesAHostileName(string $name, Engine $engine): void
    {
        $table = new TableDefinition($name, TableKind::Table, [new ColumnDefinition('id', 'integer', false, null, true, null)], [], [], null, null);
        $counter = new AutoCounter($name, 'id', '2100000000', '2147483647', Engine::PostgreSql === $engine ? 'public.s' : null, false);

        $findings = array_values(array_filter((new SchemaAuditor())->audit($engine, [$table], counters: [$counter]), static fn (Finding $f): bool => $f->table === $name));

        $this->assertNoSuggestion($findings);
    }

    #[DataProvider('cases')]
    public function testFloatAmountSuggestionRefusesAHostileName(string $name, Engine $engine): void
    {
        $table = new TableDefinition($name, TableKind::Table, [new ColumnDefinition('id', 'integer', false, null, false, null), new ColumnDefinition('price', 'double', false, null, false, null)], [new IndexDefinition('PRIMARY', ['id'], true, true)], [], null, null);

        $this->assertNoSuggestion((new SchemaAuditor())->audit($engine, [$table]));
    }

    public function testUuidKeySuggestionRefusesAHostileName(): void
    {
        $name = "x\nDROP";
        $table = new TableDefinition($name, TableKind::Table, [new ColumnDefinition('id', 'character(36)', false, null, false, null)], [new IndexDefinition('PRIMARY', ['id'], true, true)], [], null, null);

        $this->assertNoSuggestion((new SchemaAuditor())->audit(Engine::PostgreSql, [$table]));
    }
}
