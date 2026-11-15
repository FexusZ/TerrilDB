<?php

declare(strict_types=1);

namespace App\Core\Optimizer;

use App\Core\Optimizer\Exception\UnreadablePlanException;

/**
 * Lit `EXPLAIN FORMAT=JSON` de MySQL et de MariaDB (deux variantes de la même forme : `rows` ou
 * `rows_examined_per_scan`, `cost` ou `cost_info`). Le plan est un « query_block » dont les tables sont atteintes
 * par des conteneurs (nested_loop, ordering_operation, grouping_operation…). Une jointure d'une seule table n'est
 * pas une jointure : MariaDB enveloppe tout dans un nested_loop, on le replie. MySQL 26 rend par défaut le format
 * JSON v2 (un arbre « query_plan » d'opérations avec leurs « inputs ») : lu par `parseTree`.
 */
final class MySqlPlanParser implements PlanParser
{
    /**
     * Un tri sans index (`using_filesort`, `filesort`) est un nœud Sort qui prend les lignes de son entrée ; un
     * `ordering_operation` dont l'index donne l'ordre n'est pas un tri.
     *
     * @var array<string, array{PlanKind, string}>
     */
    private const WRAPPERS = [
        'ordering_operation' => [PlanKind::Sort, 'Ordering'],
        'filesort' => [PlanKind::Sort, 'Filesort'],
        'grouping_operation' => [PlanKind::Aggregate, 'Grouping'],
        'duplicates_removal' => [PlanKind::Other, 'Duplicates removal'],
        'buffer_result' => [PlanKind::Other, 'Buffer result'],
        'temporary_table' => [PlanKind::Other, 'Temporary table'],
        'windowing' => [PlanKind::Other, 'Window'],
    ];

    public function parse(string $json): QueryPlan
    {
        try {
            $data = json_decode($json, true, 64, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw UnreadablePlanException::create();
        }

        if (\is_array($data) && \is_array($data['query_plan'] ?? null)) {
            return $this->parseTree($data['query_plan']);
        }

        $block = \is_array($data) ? ($data['query_block'] ?? null) : null;
        if (!\is_array($block)) {
            throw UnreadablePlanException::create();
        }

        $costInfo = \is_array($block['cost_info'] ?? null) ? $block['cost_info'] : [];
        $root = new PlanNode(PlanKind::Other, \is_string($block['message'] ?? null) ? $block['message'] : 'Query', children: $this->collect($block));

        return new QueryPlan($root, self::number($costInfo['query_cost'] ?? $block['cost'] ?? null));
    }

    /**
     * @param array<array-key, mixed> $plan
     */
    private function parseTree(array $plan): QueryPlan
    {
        $top = $this->operation($plan, null);

        return new QueryPlan(
            new PlanNode(PlanKind::Other, 'Query', children: [$top]),
            self::number($plan['estimated_total_cost'] ?? null) ?? $top->cost,
        );
    }

    /**
     * Un filtre n'est pas un nœud : sa condition passe à l'accès à la table qu'il recouvre.
     *
     * @param array<array-key, mixed> $op
     */
    private function operation(array $op, ?string $condition): PlanNode
    {
        $inputs = array_values(array_filter(\is_array($op['inputs'] ?? null) ? $op['inputs'] : [], \is_array(...)));
        $access = \is_string($op['access_type'] ?? null) ? $op['access_type'] : 'unknown';
        $label = \is_string($op['operation'] ?? null) ? $op['operation'] : $access;

        if ('filter' === $access && 1 === \count($inputs)) {
            return $this->operation($inputs[0], \is_string($op['condition'] ?? null) ? $op['condition'] : $condition);
        }

        $children = array_map(fn (array $input): PlanNode => $this->operation($input, null), $inputs);
        $rows = self::number($op['estimated_rows'] ?? null);
        $cost = self::number($op['estimated_total_cost'] ?? null) ?? ($children[0]->cost ?? null);

        if (\is_string($op['table_name'] ?? null) && !str_starts_with($op['table_name'], '<')) {
            $full = 'table' === $access;

            return new PlanNode(
                $full ? PlanKind::FullScan : (\is_string($op['index_name'] ?? null) && 'index_scan' === ($op['index_access_type'] ?? null) ? PlanKind::IndexFullScan : PlanKind::IndexScan),
                $label,
                $op['table_name'],
                \is_string($op['index_name'] ?? null) ? $op['index_name'] : null,
                null === $rows ? null : (int) round($rows),
                $cost,
                $condition,
                $full,
            );
        }

        $kind = match ($access) {
            'join' => PlanKind::Join,
            'sort' => PlanKind::Sort,
            'aggregate' => PlanKind::Aggregate,
            default => PlanKind::Other,
        };
        if (PlanKind::Join === $kind && 1 === \count($children)) {
            return $children[0];
        }

        return new PlanNode($kind, $label, rows: null === $rows ? null : (int) round($rows), cost: $cost, condition: $condition, children: $children);
    }

    /**
     * @param array<array-key, mixed> $container
     *
     * @return list<PlanNode>
     */
    private function collect(array $container): array
    {
        $nodes = [];
        foreach ($container as $key => $value) {
            if (!\is_array($value)) {
                continue;
            }
            if ('table' === $key) {
                $nodes[] = $this->leaf($value);
            } elseif ('nested_loop' === $key && array_is_list($value)) {
                $members = [];
                foreach ($value as $member) {
                    if (\is_array($member)) {
                        array_push($members, ...$this->collect($member));
                    }
                }
                $nodes[] = 1 === \count($members) ? $members[0] : new PlanNode(PlanKind::Join, 'Nested loop', children: $members);
            } elseif (isset(self::WRAPPERS[$key])) {
                [$kind, $label] = self::WRAPPERS[$key];
                if ('ordering_operation' === $key && false === ($value['using_filesort'] ?? null)) {
                    $kind = PlanKind::Other;
                }
                $children = $this->collect($value);
                $nodes[] = new PlanNode($kind, $label, rows: PlanKind::Sort === $kind && 1 === \count($children) ? $children[0]->rows : null, children: $children);
            } elseif ('read_sorted_file' === $key) {
                array_push($nodes, ...$this->collect($value));
            } elseif ('materialized_from_subquery' === $key && \is_array($value['query_block'] ?? null)) {
                array_push($nodes, ...$this->collect($value['query_block']));
            }
        }

        return $nodes;
    }

    /**
     * @param array<array-key, mixed> $table
     */
    private function leaf(array $table): PlanNode
    {
        $access = \is_string($table['access_type'] ?? null) ? $table['access_type'] : 'unknown';
        $costInfo = \is_array($table['cost_info'] ?? null) ? $table['cost_info'] : [];
        $rows = self::number($table['rows_examined_per_scan'] ?? $table['rows'] ?? null);

        $condition = null;
        foreach (['attached_condition', 'index_condition'] as $key) {
            if (\is_string($table[$key] ?? null)) {
                $condition = $table[$key];
                break;
            }
        }

        return new PlanNode(
            match (strtolower($access)) {
                'all' => PlanKind::FullScan,
                'index' => PlanKind::IndexFullScan,
                default => PlanKind::IndexScan,
            },
            $access,
            \is_string($table['table_name'] ?? null) ? $table['table_name'] : null,
            \is_string($table['key'] ?? null) ? $table['key'] : null,
            null === $rows ? null : (int) round($rows),
            self::number($costInfo['prefix_cost'] ?? $table['cost'] ?? null),
            $condition,
            'all' === strtolower($access),
        );
    }

    private static function number(mixed $value): ?float
    {
        return \is_int($value) || \is_float($value) || (\is_string($value) && is_numeric($value)) ? (float) $value : null;
    }
}
