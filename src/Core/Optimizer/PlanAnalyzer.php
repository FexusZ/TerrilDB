<?php

declare(strict_types=1);

namespace App\Core\Optimizer;

/**
 * Cherche dans un plan ce qui mérite attention. Tout se mesure au même seuil de lignes estimées : sur une petite table,
 * lire tout ou trier tout est le bon choix. Sans estimation du moteur, on ne dit rien.
 *
 * Règles : parcours complet d'une grosse table (`full_scan`) ; si sa condition est un LIKE qui commence par un joker
 * (`leading_wildcard`), on le dit à la place, car un index ordinaire ne peut pas servir et « ajoutez un index » serait faux ; tri de beaucoup de lignes sans limite (`large_sort`) : un
 * tri seul n'indique pas un disque, d'où le silence sous un LIMIT, que PostgreSQL montre dans son plan (nœud Limit juste
 * au-dessus du tri) mais pas MySQL ni MariaDB (l'appelant le dit par `$limited`, voir `StatementLimit`).
 */
final class PlanAnalyzer
{
    public const FULL_SCAN_ROWS = 1000;

    /** LIKE (MySQL, MariaDB) ou `~~` / `~~*` (PostgreSQL, qui récrit LIKE / ILIKE) dont le motif commence par `%`. */
    private const LEADING_WILDCARD = "/(?:\\blike\\s+|\\s~~\\*?\\s+)(?:_[a-z0-9]+)?'%/i";

    /**
     * @return list<PlanFinding>
     */
    public function analyze(QueryPlan $plan, bool $limited = false): array
    {
        $findings = [];
        $this->walk($plan->root, $limited, false, $findings);

        return $findings;
    }

    /**
     * @param list<PlanFinding> $findings
     */
    private function walk(PlanNode $node, bool $limited, bool $underLimit, array &$findings): void
    {
        if ($node->fullScan && null !== $node->rows && $node->rows >= self::FULL_SCAN_ROWS) {
            $rule = null !== $node->condition && 1 === preg_match(self::LEADING_WILDCARD, $node->condition) ? 'leading_wildcard' : 'full_scan';
            $findings[] = new PlanFinding($rule, $node->relation, $node->rows, $node->condition);
        }
        if (PlanKind::Sort === $node->kind && !$limited && !$underLimit && null !== $node->rows && $node->rows >= self::FULL_SCAN_ROWS) {
            $findings[] = new PlanFinding('large_sort', self::firstRelation($node), $node->rows, null);
        }
        foreach ($node->children as $child) {
            $this->walk($child, $limited, PlanKind::Limit === $node->kind, $findings);
        }
    }

    private static function firstRelation(PlanNode $node): ?string
    {
        if (null !== $node->relation) {
            return $node->relation;
        }
        foreach ($node->children as $child) {
            $relation = self::firstRelation($child);
            if (null !== $relation) {
                return $relation;
            }
        }

        return null;
    }
}
