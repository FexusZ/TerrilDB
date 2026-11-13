<?php

declare(strict_types=1);

namespace App\Core\Optimizer;

use App\Core\Optimizer\Exception\UnreadablePlanException;

/**
 * Lit `EXPLAIN (FORMAT JSON)` de PostgreSQL : un arbre de nœuds « Plan » imbriqués par « Plans ».
 */
final class PostgreSqlPlanParser implements PlanParser
{
    private const CONDITIONS = ['Filter', 'Index Cond', 'Recheck Cond', 'Hash Cond', 'Merge Cond', 'Join Filter'];

    public function parse(string $json): QueryPlan
    {
        try {
            $data = json_decode($json, true, 64, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw UnreadablePlanException::create();
        }

        $plan = \is_array($data) && isset($data[0]) && \is_array($data[0]) ? ($data[0]['Plan'] ?? null) : null;
        if (!\is_array($plan) || !\is_string($plan['Node Type'] ?? null)) {
            throw UnreadablePlanException::create();
        }

        return new QueryPlan($this->node($plan), self::number($plan['Total Cost'] ?? null));
    }

    /**
     * @param array<array-key, mixed> $plan
     */
    private function node(array $plan): PlanNode
    {
        $type = \is_string($plan['Node Type'] ?? null) ? $plan['Node Type'] : 'Unknown';

        $children = [];
        if (\is_array($plan['Plans'] ?? null)) {
            foreach ($plan['Plans'] as $child) {
                if (\is_array($child)) {
                    $children[] = $this->node($child);
                }
            }
        }

        $condition = null;
        foreach (self::CONDITIONS as $key) {
            if (\is_string($plan[$key] ?? null)) {
                $condition = $plan[$key];
                break;
            }
        }

        $rows = self::number($plan['Plan Rows'] ?? null);
        $writes = 'ModifyTable' === $type && \is_string($plan['Operation'] ?? null) && \in_array(strtolower($plan['Operation']), ['insert', 'update', 'delete'], true) ? strtolower($plan['Operation']) : null;
        if ('ModifyTable' === $type) {
            // « Plan Rows » d'une écriture vaut 0 : les lignes touchées sont celles de son accès, en dessous.
            $rows = null;
        }

        return new PlanNode(
            self::kind($type),
            $type,
            \is_string($plan['Relation Name'] ?? null) ? $plan['Relation Name'] : null,
            \is_string($plan['Index Name'] ?? null) ? $plan['Index Name'] : null,
            null === $rows ? null : (int) round($rows),
            self::number($plan['Total Cost'] ?? null),
            $condition,
            'Seq Scan' === $type,
            $children,
            $writes,
        );
    }

    private static function kind(string $type): PlanKind
    {
        return match ($type) {
            'Seq Scan' => PlanKind::FullScan,
            'Index Scan', 'Index Only Scan', 'Bitmap Heap Scan', 'Bitmap Index Scan' => PlanKind::IndexScan,
            'Nested Loop', 'Hash Join', 'Merge Join' => PlanKind::Join,
            'Sort', 'Incremental Sort' => PlanKind::Sort,
            'Aggregate', 'HashAggregate', 'GroupAggregate', 'MixedAggregate' => PlanKind::Aggregate,
            'Limit' => PlanKind::Limit,
            default => PlanKind::Other,
        };
    }

    private static function number(mixed $value): ?float
    {
        return \is_int($value) || \is_float($value) || (\is_string($value) && is_numeric($value)) ? (float) $value : null;
    }
}
