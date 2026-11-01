<?php

declare(strict_types=1);

namespace App\Core\Optimizer;

use App\Core\Optimizer\Exception\UnreadablePlanException;

/**
 * Lit `EXPLAIN FORMAT=JSON` de MySQL et de MariaDB (deux variantes de la même forme : `rows` ou
 * `rows_examined_per_scan`, `cost` ou `cost_info`). Le plan est un « query_block » dont les tables sont atteintes
 * par des conteneurs (nested_loop, ordering_operation, grouping_operation…). Une jointure d'une seule table n'est
 * pas une jointure : MariaDB enveloppe tout dans un nested_loop, on le replie.
 */
final class MySqlPlanParser implements PlanParser
{
    /** @var array<string, array{PlanKind, string}> */
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

        $block = \is_array($data) ? ($data['query_block'] ?? null) : null;
        if (!\is_array($block)) {
            throw UnreadablePlanException::create();
        }

        $costInfo = \is_array($block['cost_info'] ?? null) ? $block['cost_info'] : [];
        $root = new PlanNode(PlanKind::Other, \is_string($block['message'] ?? null) ? $block['message'] : 'Query', children: $this->collect($block));

        return new QueryPlan($root, self::number($costInfo['query_cost'] ?? $block['cost'] ?? null));
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
                $nodes[] = new PlanNode($kind, $label, children: $this->collect($value));
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
