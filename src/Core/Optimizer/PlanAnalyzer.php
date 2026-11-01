<?php

declare(strict_types=1);

namespace App\Core\Optimizer;

/**
 * Cherche dans un plan ce qui mérite attention. Seul un parcours complet d'une table que le moteur estime grande est
 * signalé : sur une petite table, lire tout est le bon choix. Sans estimation du moteur, on ne dit rien.
 */
final class PlanAnalyzer
{
    public const FULL_SCAN_ROWS = 1000;

    /**
     * @return list<PlanFinding>
     */
    public function analyze(QueryPlan $plan): array
    {
        $findings = [];
        $this->walk($plan->root, $findings);

        return $findings;
    }

    /**
     * @param list<PlanFinding> $findings
     */
    private function walk(PlanNode $node, array &$findings): void
    {
        if ($node->fullScan && null !== $node->rows && $node->rows >= self::FULL_SCAN_ROWS) {
            $findings[] = new PlanFinding('full_scan', $node->relation, $node->rows, $node->condition);
        }
        foreach ($node->children as $child) {
            $this->walk($child, $findings);
        }
    }
}
