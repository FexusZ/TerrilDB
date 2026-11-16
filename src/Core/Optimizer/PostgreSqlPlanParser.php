<?php

declare(strict_types=1);

namespace App\Core\Optimizer;

use App\Core\Optimizer\Exception\UnreadablePlanException;

/**
 * Lit `EXPLAIN (FORMAT JSON)` de PostgreSQL : un arbre de nœuds « Plan » imbriqués par « Plans ».
 */
final class PostgreSqlPlanParser implements PlanParser, MeasuredPlanParser
{
    private const CONDITIONS = ['Filter', 'Index Cond', 'Recheck Cond', 'Hash Cond', 'Merge Cond', 'Join Filter'];

    public function parse(string $json): QueryPlan
    {
        [, $plan] = $this->read($json);

        return new QueryPlan($this->node($plan), self::number($plan['Total Cost'] ?? null));
    }

    /**
     * `EXPLAIN (ANALYZE, FORMAT JSON, BUFFERS)` : la même forme, avec « Actual … » sur chaque nœud et « Execution Time » en tête.
     */
    public function parseMeasured(string $output): QueryPlan
    {
        [$data, $plan] = $this->read($output);
        if (!isset($plan['Actual Rows'])) {
            throw UnreadablePlanException::create();
        }

        return new QueryPlan($this->node($plan), self::number($plan['Total Cost'] ?? null), true, self::number($data['Execution Time'] ?? null));
    }

    /**
     * @return array{array<array-key, mixed>, array<array-key, mixed>}
     */
    private function read(string $json): array
    {
        try {
            $data = json_decode($json, true, 64, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw UnreadablePlanException::create();
        }

        $top = \is_array($data) && isset($data[0]) && \is_array($data[0]) ? $data[0] : [];
        $plan = $top['Plan'] ?? null;
        if (!\is_array($plan) || !\is_string($plan['Node Type'] ?? null)) {
            throw UnreadablePlanException::create();
        }

        return [$top, $plan];
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
            self::count($plan['Actual Rows'] ?? null),
            self::number($plan['Actual Total Time'] ?? null),
            self::count($plan['Actual Loops'] ?? null),
            self::buffers($plan),
            \in_array($type, ['Sort', 'Incremental Sort'], true) && 'Disk' === ($plan['Sort Space Type'] ?? null),
        );
    }

    /**
     * @param array<array-key, mixed> $plan
     */
    private static function buffers(array $plan): ?PlanBuffers
    {
        if (!isset($plan['Shared Hit Blocks']) && !isset($plan['Temp Written Blocks'])) {
            return null;
        }

        return new PlanBuffers(
            self::count($plan['Shared Hit Blocks'] ?? null) ?? 0,
            self::count($plan['Shared Read Blocks'] ?? null) ?? 0,
            self::count($plan['Temp Read Blocks'] ?? null) ?? 0,
            self::count($plan['Temp Written Blocks'] ?? null) ?? 0,
        );
    }

    private static function count(mixed $value): ?int
    {
        $number = self::number($value);

        return null === $number ? null : (int) round($number);
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
