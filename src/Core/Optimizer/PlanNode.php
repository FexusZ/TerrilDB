<?php

declare(strict_types=1);

namespace App\Core\Optimizer;

/**
 * Un nœud d'un plan d'exécution. Les nombres (lignes, coût) sont les estimations du moteur, pas des mesures.
 */
final readonly class PlanNode
{
    /**
     * @param list<PlanNode> $children
     */
    public function __construct(
        public PlanKind $kind,
        public string $label,
        public ?string $relation = null,
        public ?string $index = null,
        public ?int $rows = null,
        public ?float $cost = null,
        public ?string $condition = null,
        public bool $fullScan = false,
        public array $children = [],
    ) {
    }
}
