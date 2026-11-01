<?php

declare(strict_types=1);

namespace App\Core\Optimizer;

/**
 * Un constat sur un plan d'exécution (pour l'instant : un gros parcours complet).
 */
final readonly class PlanFinding
{
    public function __construct(
        public string $rule,
        public ?string $relation,
        public ?int $rows,
        public ?string $condition,
    ) {
    }
}
