<?php

declare(strict_types=1);

namespace App\Core\Optimizer;

use App\Core\Connection\Engine;

/**
 * Lit le plan estimé d'une écriture (EXPLAIN seul, rien n'a été exécuté) : combien de lignes environ, par quel accès,
 * et deux avis prudents. Sous le seuil de lignes, rien n'est dit : sur une petite table, lire tout est le bon choix.
 * Sans estimation du moteur, pas de verdict non plus.
 */
final class WritePlanAdvisor
{
    /** Au-delà, une écriture en un seul coup gonfle la transaction et les verrous : on conseille les lots. */
    public const BATCH_ROWS = 10000;

    public function advise(QueryPlan $plan, Engine $engine): ?WritePlanSummary
    {
        $write = self::findWrite($plan->root);
        if (null === $write || null === $write->writes) {
            return null;
        }

        $insert = 'insert' === $write->writes;
        $access = $insert ? null : self::accessOf($write);
        $source = $insert ? ($write->children[0] ?? null) : null;
        $rows = $insert ? $source?->rows : $access?->rows;
        $fullScan = null !== $access && $access->fullScan;
        $big = null !== $rows && $rows >= PlanAnalyzer::FULL_SCAN_ROWS;

        return new WritePlanSummary(
            $write->writes,
            $write->relation,
            $rows,
            $fullScan,
            $access?->index,
            null !== $rows && $rows >= self::BATCH_ROWS,
            !$insert && $fullScan && $big && \in_array($engine, [Engine::MySql, Engine::MariaDb], true),
        );
    }

    private static function findWrite(PlanNode $node): ?PlanNode
    {
        if (null !== $node->writes) {
            return $node;
        }
        foreach ($node->children as $child) {
            $found = self::findWrite($child);
            if (null !== $found) {
                return $found;
            }
        }

        return null;
    }

    /**
     * MySQL et MariaDB : le nœud d'écriture est lui-même l'accès à la table. PostgreSQL : l'accès est le premier
     * parcours de la table écrite sous le nœud ModifyTable.
     */
    private static function accessOf(PlanNode $write): ?PlanNode
    {
        if (self::isScan($write)) {
            return $write;
        }

        return self::firstScanOf($write->relation, $write->children);
    }

    /**
     * @param list<PlanNode> $nodes
     */
    private static function firstScanOf(?string $relation, array $nodes): ?PlanNode
    {
        foreach ($nodes as $node) {
            if (self::isScan($node) && $node->relation === $relation) {
                return $node;
            }
            $found = self::firstScanOf($relation, $node->children);
            if (null !== $found) {
                return $found;
            }
        }

        return null;
    }

    private static function isScan(PlanNode $node): bool
    {
        return \in_array($node->kind, [PlanKind::FullScan, PlanKind::IndexFullScan, PlanKind::IndexScan], true);
    }
}
