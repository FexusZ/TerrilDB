<?php

declare(strict_types=1);

namespace App\Core\Optimizer;

use App\Core\Optimizer\Exception\UnreadablePlanException;

/**
 * Lit `EXPLAIN json=1, indexes=1` de ClickHouse : un arbre de nœuds « Plan » imbriqués par « Plans ». Le moteur ne donne
 * ni coût ni nombre de lignes dans ce format : ils restent nuls, et rien n'est donc signalé par `PlanAnalyzer`.
 * Une lecture est un parcours complet quand la clé de tri ne la restreint pas (condition « true »).
 */
final class ClickHousePlanParser implements PlanParser
{
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

        return new QueryPlan($this->node($plan), null);
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

        if (str_starts_with($type, 'ReadFromMergeTree')) {
            return $this->read($plan, $type, $children);
        }

        return new PlanNode(
            kind: self::kind($type),
            label: $type,
            children: $children,
        );
    }

    /**
     * @param array<array-key, mixed> $plan
     * @param list<PlanNode>          $children
     */
    private function read(array $plan, string $type, array $children): PlanNode
    {
        $description = \is_string($plan['Description'] ?? null) ? $plan['Description'] : null;
        $relation = null === $description ? null : substr($description, (int) strrpos('.'.$description, '.'));

        $index = null;
        $condition = null;
        if (\is_array($plan['Indexes'] ?? null)) {
            foreach ($plan['Indexes'] as $candidate) {
                if (!\is_array($candidate) || 'PrimaryKey' !== ($candidate['Type'] ?? null)) {
                    continue;
                }
                $text = $candidate['Condition'] ?? null;
                if (\is_string($text) && 'true' !== $text) {
                    $index = 'PRIMARY KEY';
                    $condition = $text;
                }
            }
        }

        $fullScan = null === $index;

        return new PlanNode(
            kind: $fullScan ? PlanKind::FullScan : PlanKind::IndexScan,
            label: $type,
            relation: $relation,
            index: $index,
            condition: $condition,
            fullScan: $fullScan,
            children: $children,
        );
    }

    private static function kind(string $type): PlanKind
    {
        return match (true) {
            'Sorting' === $type, 'MergeSorting' === $type, 'PartialSorting' === $type => PlanKind::Sort,
            str_contains($type, 'Aggregating') => PlanKind::Aggregate,
            'Limit' === $type, 'Offset' === $type => PlanKind::Limit,
            'Join' === $type, 'FilledJoin' === $type => PlanKind::Join,
            default => PlanKind::Other,
        };
    }
}
