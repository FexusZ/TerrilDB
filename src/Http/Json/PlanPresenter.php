<?php

declare(strict_types=1);

namespace App\Http\Json;

use App\Core\Optimizer\PlanFinding;
use App\Core\Optimizer\PlanNode;
use App\Core\Optimizer\QueryPlan;

final class PlanPresenter
{
    /**
     * @param list<PlanFinding> $findings
     *
     * @return array<string, mixed>
     */
    public static function present(QueryPlan $plan, array $findings): array
    {
        return [
            'plan' => ['totalCost' => $plan->totalCost, 'root' => self::node($plan->root)],
            'findings' => array_map(static fn (PlanFinding $f): array => ['rule' => $f->rule, 'relation' => $f->relation, 'rows' => $f->rows, 'condition' => $f->condition], $findings),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function node(PlanNode $node): array
    {
        return [
            'kind' => $node->kind->value,
            'label' => $node->label,
            'relation' => $node->relation,
            'index' => $node->index,
            'rows' => $node->rows,
            'cost' => $node->cost,
            'condition' => $node->condition,
            'fullScan' => $node->fullScan,
            'children' => array_map(self::node(...), $node->children),
        ];
    }
}
