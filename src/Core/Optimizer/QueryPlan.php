<?php

declare(strict_types=1);

namespace App\Core\Optimizer;

/**
 * @param bool   $measured        vrai quand le plan vient d'un EXPLAIN ANALYZE : la requête a été jouée et les nœuds portent leurs chiffres réels
 * @param ?float $executionTimeMs durée d'exécution mesurée de la requête entière (planification exclue), null si non mesurée
 */
final readonly class QueryPlan
{
    public function __construct(
        public PlanNode $root,
        public ?float $totalCost,
        public bool $measured = false,
        public ?float $executionTimeMs = null,
    ) {
    }
}
