<?php

declare(strict_types=1);

namespace App\Core\Optimizer;

use App\Core\Optimizer\Exception\UnreadablePlanException;

/**
 * Lit `EXPLAIN ANALYZE` de MySQL (8.4 et 26) : un arbre de TEXTE, une opération par ligne (`-> …`, quatre espaces par niveau),
 * chacune suivie de `(cost=… rows=…)` puis de `(actual time=premier..dernier rows=… loops=…)`. MySQL 8.4 ne sait pas rendre
 * ce plan en JSON ; MySQL 26 le sait (JSON v2) mais la forme texte, commune aux deux, est la seule lue ici. Les lignes et
 * les temps sont PAR exécution du nœud, comme PostgreSQL ; les noms de table sont ceux de la requête (alias compris).
 * Le texte ne dit pas où un tri s'est fait : `sortsOnDisk` reste faux.
 */
final class MySqlTreePlanParser implements MeasuredPlanParser
{
    private const NUMBER = '[0-9]+(?:\.[0-9]+)?(?:[eE][+-]?[0-9]+)?';

    public function parseMeasured(string $output): QueryPlan
    {
        $entries = [];
        foreach (preg_split('/\R/', $output) ?: [] as $line) {
            if (1 === preg_match('/^(\s*)-> (.+)$/', $line, $m)) {
                $entries[] = [\strlen($m[1]), $m[2]];
            }
        }
        if ([] === $entries) {
            throw UnreadablePlanException::create();
        }

        $position = 0;
        $top = $this->build($entries, $position, $entries[0][0]);
        if (null === $top || $position < \count($entries)) {
            throw UnreadablePlanException::create();
        }

        return new QueryPlan(new PlanNode(PlanKind::Other, 'Query', children: [$top]), $top->cost, true, $top->actualTimeMs);
    }

    /**
     * @param list<array{int, string}> $entries
     */
    private function build(array $entries, int &$position, int $indent): ?PlanNode
    {
        [$depth, $text] = $entries[$position];
        if ($depth !== $indent) {
            return null;
        }
        ++$position;

        $children = [];
        while ($position < \count($entries) && $entries[$position][0] > $indent) {
            $child = $this->build($entries, $position, $entries[$position][0]);
            if (null === $child) {
                return null;
            }
            $children[] = $child;
        }

        return $this->node($text, $children);
    }

    /**
     * @param list<PlanNode> $children
     */
    private function node(string $text, array $children): PlanNode
    {
        $label = $text;
        $cost = $rows = null;
        $actualRows = $actualTime = $loops = null;

        if (1 === preg_match('/^(.*?)\s+\(cost=('.self::NUMBER.') rows=('.self::NUMBER.')\)/', $text, $m)) {
            $label = $m[1];
            $cost = (float) $m[2];
            $rows = (int) round((float) $m[3]);
        }
        if (1 === preg_match('/\(actual time=(?:'.self::NUMBER.')\.\.('.self::NUMBER.') rows=('.self::NUMBER.') loops=([0-9]+)\)/', $text, $m)) {
            $actualTime = (float) $m[1];
            $actualRows = (int) round((float) $m[2]);
            $loops = (int) $m[3];
        } elseif (str_contains($text, '(never executed)')) {
            $actualRows = 0;
            $loops = 0;
        }

        // Un filtre n'est pas un nœud : sa condition passe à l'accès à la table qu'il recouvre (qui garde ses propres chiffres).
        if (str_starts_with($label, 'Filter: ') && 1 === \count($children)) {
            $child = $children[0];

            return new PlanNode($child->kind, $child->label, $child->relation, $child->index, $child->rows, $child->cost, substr($label, 8), $child->fullScan, $child->children, null, $child->actualRows, $child->actualTimeMs, $child->loops, null, $child->sortsOnDisk);
        }

        $relation = null;
        $index = null;
        $kind = match (true) {
            str_starts_with($label, 'Sort:'), str_starts_with($label, 'Sort row IDs:') => PlanKind::Sort,
            str_starts_with($label, 'Limit') => PlanKind::Limit,
            str_starts_with($label, 'Aggregate'), str_starts_with($label, 'Group aggregate'), str_starts_with($label, 'Group (') => PlanKind::Aggregate,
            str_contains($label, 'join') => PlanKind::Join,
            default => PlanKind::Other,
        };
        $full = false;
        if (1 === preg_match('/^(Table scan|Index scan|Covering index scan|Covering index lookup|Index lookup|Single-row index lookup|Single-row covering index lookup|Index range scan|Covering index range scan|Index skip scan|Constant row) on (\S+)(?: using (\S+))?/', $label, $m)) {
            $relation = str_starts_with($m[2], '<') ? null : $m[2];
            $index = $m[3] ?? null;
            $full = 'Table scan' === $m[1];
            $kind = match (true) {
                $full => PlanKind::FullScan,
                \in_array($m[1], ['Index scan', 'Covering index scan'], true) => PlanKind::IndexFullScan,
                default => PlanKind::IndexScan,
            };
        }

        return new PlanNode($kind, $label, $relation, $index, $rows, $cost, null, $full, $children, null, $actualRows, $actualTime, $loops);
    }
}
