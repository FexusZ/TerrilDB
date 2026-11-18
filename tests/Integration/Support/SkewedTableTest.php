<?php

declare(strict_types=1);

namespace App\Tests\Integration\Support;

use App\Core\Guard\StatementGuard;
use App\Core\Optimizer\PlanKind;
use App\Core\Optimizer\PlanNode;
use App\Core\Optimizer\PlanService;
use App\Core\Query\QueryResult;
use App\Tests\Integration\Driver\TargetDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * La grande table déséquilibrée, sur chaque cible : sa forme (lignes, répartition, absence d'index) et un usage réel (le plan
 * d'une requête sur la valeur rare change quand on crée l'index). Tout est créé puis supprimé par le test.
 */
#[Group('integration')]
final class SkewedTableTest extends TestCase
{
    /** @return iterable<string, array{string}> */
    public static function targets(): iterable
    {
        return TargetDatabase::all();
    }

    #[DataProvider('targets')]
    public function testHasTheAnnouncedShapeAndIsRemovedAfterwards(string $env): void
    {
        $table = SkewedTable::create($env);
        try {
            self::assertSame((string) SkewedTable::ROWS, $table->scalar(\sprintf('SELECT COUNT(*) FROM %s', $table->name)));
            self::assertSame((string) SkewedTable::LOOKUP_ROWS, $table->scalar(\sprintf('SELECT COUNT(*) FROM %s', $table->lookup)));
            self::assertSame(
                ['done' => 120_000, 'flagged' => 10_000, 'pending' => 70_000],
                $this->distribution($table),
            );
            self::assertSame('365', $table->scalar(\sprintf('SELECT COUNT(DISTINCT created_on) FROM %s', $table->name)));
            // Chaque ligne pointe une ligne de la petite table (la clé étrangère n'est pas déclarée : MySQL lui créerait un index).
            self::assertSame((string) SkewedTable::ROWS, $table->scalar(\sprintf('SELECT COUNT(*) FROM %s t JOIN %s l ON l.id = t.lookup_id', $table->name, $table->lookup)));
            self::assertSame($table->secondaryIndexes(), []);
            self::assertGreaterThanOrEqual(0.0, $table->loadSeconds);
        } finally {
            $table->drop();
        }

        self::assertSame([], SkewedTable::leftovers($env), 'The dataset must leave nothing behind.');
    }

    #[DataProvider('targets')]
    public function testIsRemovedEvenWhenTheTestFails(string $env): void
    {
        $table = SkewedTable::create($env, 1000);
        try {
            throw new \RuntimeException('boom');
        } catch (\RuntimeException) {
            // Le test qui échoue passe par le finally.
        } finally {
            $table->drop();
        }

        self::assertSame([], SkewedTable::leftovers($env));
    }

    /** @return iterable<string, array{string}> */
    public static function relational(): iterable
    {
        return TargetDatabase::relational();
    }

    #[DataProvider('relational')]
    public function testTheIndexOnTheRareValueChangesThePlanFromAFullScanToTheIndex(string $env): void
    {
        $table = SkewedTable::create($env);
        try {
            $sql = \sprintf("SELECT id, amount FROM %s WHERE status = 'flagged' AND created_on = '2025-03-01'", $table->name);
            $before = $this->scan($env, $table, $sql);

            $table->exec(\sprintf('CREATE INDEX %s_status ON %s (status, created_on)', $table->name, $table->name));
            $table->analyze();
            $after = $this->scan($env, $table, $sql);
        } finally {
            $table->drop();
        }

        self::assertSame(PlanKind::FullScan, $before->kind, 'Without an index the whole table is read.');
        self::assertNull($before->index);
        self::assertSame(PlanKind::IndexScan, $after->kind, 'With the index the engine reads only what it needs.');
        self::assertStringContainsString('_status', (string) $after->index);
    }

    /** @return array<string, int> */
    private function distribution(SkewedTable $table): array
    {
        $counts = $table->rows(\sprintf('SELECT status, COUNT(*) FROM %s GROUP BY status ORDER BY status', $table->name));
        $out = [];
        foreach ($counts as $row) {
            $out[\is_scalar($row[0]) ? (string) $row[0] : ''] = is_numeric($row[1]) ? (int) $row[1] : 0;
        }

        return $out;
    }

    /** Le nœud de lecture de la table dans le plan que TerrilDB lit (PlanService), pas dans le texte du moteur. */
    private function scan(string $env, SkewedTable $table, string $sql): PlanNode
    {
        $engine = TargetDatabase::engine($env);
        $service = new PlanService(new StatementGuard());
        $json = $table->scalar($service->statementFor($engine, $sql));
        $plan = $service->parse($engine, QueryResult::rows(['EXPLAIN'], [[$json]], false, 0));

        return self::readNode($plan->root, $table->name) ?? self::fail('No node reads the table.');
    }

    private static function readNode(PlanNode $node, string $relation): ?PlanNode
    {
        if ($node->relation === $relation && \in_array($node->kind, [PlanKind::FullScan, PlanKind::IndexScan, PlanKind::IndexFullScan], true)) {
            return $node;
        }
        foreach ($node->children as $child) {
            $found = self::readNode($child, $relation);
            if (null !== $found) {
                return $found;
            }
        }

        return null;
    }
}
