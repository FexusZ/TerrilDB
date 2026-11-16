<?php

declare(strict_types=1);

namespace App\Core\Optimizer;

use App\Core\Optimizer\Exception\UnreadablePlanException;

/**
 * Lit `ANALYZE FORMAT=JSON` de MariaDB (11.8 et 13) : l'EXPLAIN JSON ordinaire, plus des champs `r_…` (r pour « real ») mesurés
 * pendant l'exécution : `r_rows` (lignes par boucle, comme `rows` qu'on lui compare), `r_loops`, temps en millisecondes pour
 * TOUTES les boucles (rapportés ici à UNE exécution), et pour un tri `r_sort_passes` (passes de fusion : au moins une = le tri
 * a débordé de son tampon sur disque). Les noms de table sont ceux de la requête (alias compris).
 */
final class MariaDbAnalyzeParser implements MeasuredPlanParser
{
    public function parseMeasured(string $output): QueryPlan
    {
        try {
            $data = json_decode($output, true, 64, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw UnreadablePlanException::create();
        }

        $block = \is_array($data) ? ($data['query_block'] ?? null) : null;
        if (!\is_array($block) || !isset($block['r_loops'])) {
            throw UnreadablePlanException::create();
        }

        $time = self::number($block['r_total_time_ms'] ?? null);
        $root = new PlanNode(PlanKind::Other, 'Query', children: $this->collect($block), actualTimeMs: $time, loops: self::count($block['r_loops']));

        return new QueryPlan($root, self::number($block['cost'] ?? null), true, $time);
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
                $nodes[] = $this->table($value);
            } elseif ('filesort' === $key) {
                $nodes[] = $this->sort($value);
            } elseif ('nested_loop' === $key && array_is_list($value)) {
                foreach ($value as $member) {
                    if (\is_array($member)) {
                        array_push($nodes, ...$this->collect($member));
                    }
                }
            } elseif (!array_is_list($value)) {
                array_push($nodes, ...$this->collect($value));
            }
        }

        return $nodes;
    }

    /**
     * @param array<array-key, mixed> $sort
     */
    private function sort(array $sort): PlanNode
    {
        return new PlanNode(
            PlanKind::Sort,
            'Filesort',
            children: $this->collect($sort),
            actualRows: self::count($sort['r_output_rows'] ?? null),
            actualTimeMs: self::perLoop($sort['r_total_time_ms'] ?? null, $sort['r_loops'] ?? null),
            loops: self::count($sort['r_loops'] ?? null),
            sortsOnDisk: (self::count($sort['r_sort_passes'] ?? null) ?? 0) > 0,
        );
    }

    /**
     * @param array<array-key, mixed> $table
     */
    private function table(array $table): PlanNode
    {
        $access = \is_string($table['access_type'] ?? null) ? $table['access_type'] : 'unknown';
        $condition = null;
        foreach (['attached_condition', 'index_condition'] as $key) {
            if (\is_string($table[$key] ?? null)) {
                $condition = $table[$key];
                break;
            }
        }
        $rows = self::number($table['rows'] ?? null);
        $time = null;
        foreach (['r_table_time_ms', 'r_other_time_ms'] as $key) {
            $part = self::number($table[$key] ?? null);
            $time = null === $part ? $time : ($time ?? 0.0) + $part;
        }
        $full = 'ALL' === $access;

        return new PlanNode(
            match (true) {
                $full => PlanKind::FullScan,
                'index' === $access => PlanKind::IndexFullScan,
                default => PlanKind::IndexScan,
            },
            $access,
            \is_string($table['table_name'] ?? null) ? $table['table_name'] : null,
            \is_string($table['key'] ?? null) ? $table['key'] : null,
            null === $rows ? null : (int) round($rows),
            self::number($table['cost'] ?? null),
            $condition,
            $full,
            actualRows: self::count($table['r_rows'] ?? null),
            actualTimeMs: self::perLoop($time, $table['r_loops'] ?? null),
            loops: self::count($table['r_loops'] ?? null),
        );
    }

    private static function perLoop(mixed $total, mixed $loops): ?float
    {
        $total = self::number($total);
        $loops = self::count($loops);

        return null === $total ? null : $total / max(1, $loops ?? 1);
    }

    private static function number(mixed $value): ?float
    {
        return \is_int($value) || \is_float($value) || (\is_string($value) && is_numeric($value)) ? (float) $value : null;
    }

    private static function count(mixed $value): ?int
    {
        $number = self::number($value);

        return null === $number ? null : (int) round($number);
    }
}
