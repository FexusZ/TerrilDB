<?php

declare(strict_types=1);

namespace App\Http\Json;

use App\Core\Optimizer\PlanFinding;
use App\Core\Optimizer\PlanNode;
use App\Core\Optimizer\QueryPlan;
use App\Core\Optimizer\WritePlanSummary;

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
     * Le plan d'une écriture : le plan et ses constats, plus le résumé (null si le moteur n'a pas montré d'écriture
     * dans son plan, ce que fait MariaDB pour un INSERT … SELECT : on n'y voit que le SELECT).
     *
     * @param list<PlanFinding> $findings
     *
     * @return array<string, mixed>
     */
    public static function presentWrite(QueryPlan $plan, array $findings, ?WritePlanSummary $write): array
    {
        return self::present($plan, $findings) + ['write' => null === $write ? null : [
            'operation' => $write->operation,
            'relation' => $write->relation,
            'rows' => $write->rows,
            'fullScan' => $write->fullScan,
            'index' => $write->index,
            'batchAdvised' => $write->batchAdvised,
            'locksWide' => $write->locksWide,
        ]];
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
