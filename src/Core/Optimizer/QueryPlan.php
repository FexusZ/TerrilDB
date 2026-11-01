<?php

declare(strict_types=1);

namespace App\Core\Optimizer;

final readonly class QueryPlan
{
    public function __construct(
        public PlanNode $root,
        public ?float $totalCost,
    ) {
    }
}
