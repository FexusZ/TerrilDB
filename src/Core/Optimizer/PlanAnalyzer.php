<?php

declare(strict_types=1);

namespace App\Core\Optimizer;

use App\Core\Connection\Engine;

/**
 * Cherche dans un plan ce qui mérite attention. Seul un parcours complet d'une table que le moteur estime grande est
 * signalé : sur une petite table, lire tout est le bon choix. Sans estimation du moteur, on ne dit rien.
 *
 * Sur un plan MESURÉ (EXPLAIN ANALYZE), deux règles de plus, qui exigent des chiffres connus : `stale_estimate` (l'estimation
 * d'un accès à une table s'écarte du réel d'un facteur d'au moins 100, pour au moins 1 000 lignes réelles : les statistiques du
 * moteur sont probablement périmées) et `sort_on_disk` (un tri a débordé de la mémoire).
 */
final class PlanAnalyzer
{
    public const FULL_SCAN_ROWS = 1000;
    public const STALE_FACTOR = 100;
    public const STALE_MIN_ROWS = 1000;

    /**
     * @param ?string $sql l'instruction jugée : sert seulement à vérifier qu'un nom de table du plan MySQL/MariaDB (qui peut être un
     *                     alias) est bien une table avant de suggérer un ANALYZE
     *
     * @return list<PlanFinding>
     */
    public function analyze(QueryPlan $plan, ?Engine $engine = null, ?string $sql = null): array
    {
        $findings = [];
        $stale = [];
        $this->walk($plan->root, $engine, $sql, $findings, $stale);

        return $findings;
    }

    /**
     * @param list<PlanFinding>  $findings
     * @param array<string,true> $stale
     */
    private function walk(PlanNode $node, ?Engine $engine, ?string $sql, array &$findings, array &$stale): void
    {
        if ($node->fullScan && null !== $node->rows && $node->rows >= self::FULL_SCAN_ROWS) {
            $findings[] = new PlanFinding('full_scan', $node->relation, $node->rows, $node->condition);
        }
        if ($node->sortsOnDisk) {
            $findings[] = new PlanFinding('sort_on_disk', null, $node->rows, null, $node->actualRows);
        }
        if (null !== $node->relation && !isset($stale[$node->relation]) && self::isFarOff($node->rows, $node->actualRows)) {
            $stale[$node->relation] = true;
            $findings[] = new PlanFinding('stale_estimate', $node->relation, $node->rows, $node->condition, $node->actualRows, self::suggestion($node->relation, $engine, $sql));
        }
        foreach ($node->children as $child) {
            $this->walk($child, $engine, $sql, $findings, $stale);
        }
    }

    private static function isFarOff(?int $estimated, ?int $actual): bool
    {
        if (null === $estimated || null === $actual || $actual < self::STALE_MIN_ROWS) {
            return false;
        }

        return max($estimated, $actual) >= self::STALE_FACTOR * max(1, min($estimated, $actual));
    }

    private static function suggestion(string $relation, ?Engine $engine, ?string $sql): ?string
    {
        return match ($engine) {
            Engine::PostgreSql => 'ANALYZE "'.str_replace('"', '""', $relation).'";',
            Engine::MySql, Engine::MariaDb => null !== $sql && !self::isAlias($sql, $relation) ? 'ANALYZE TABLE `'.str_replace('`', '``', $relation).'`;' : null,
            default => null,
        };
    }

    /**
     * MySQL et MariaDB nomment dans leur plan l'alias de la requête, pas la table : on ne suggère un ANALYZE que si le nom n'est
     * l'alias d'aucune table du texte (un faux positif ne fait que taire la suggestion).
     */
    private static function isAlias(string $sql, string $name): bool
    {
        $quoted = preg_quote($name, '/');

        return 1 === preg_match('/(?:\b(?:from|join)|,)\s*[`"]?[\w$.]+[`"]?\s+(?:as\s+)?[`"]?'.$quoted.'[`"]?(?![\w$])/i', $sql);
    }
}
