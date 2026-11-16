<?php

declare(strict_types=1);

namespace App\Core\Optimizer;

use App\Core\Connection\Engine;

/**
 * Cherche dans un plan ce qui mérite attention. Tout se mesure au même seuil de lignes estimées : sur une petite table,
 * lire tout ou trier tout est le bon choix. Sans estimation du moteur, on ne dit rien.
 *
 * Règles : parcours complet d'une grosse table (`full_scan`) ; si sa condition est un LIKE qui commence par un joker
 * (`leading_wildcard`), on le dit à la place, car un index ordinaire ne peut pas servir et « ajoutez un index » serait faux ; tri de beaucoup de lignes sans limite (`large_sort`) : un
 * tri seul n'indique pas un disque, d'où le silence sous un LIMIT, que PostgreSQL montre dans son plan (nœud Limit juste
 * au-dessus du tri) mais pas MySQL ni MariaDB (l'appelant le dit par `$limited`, voir `StatementLimit`).
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

    /** LIKE (MySQL, MariaDB) ou `~~` / `~~*` (PostgreSQL, qui récrit LIKE / ILIKE) dont le motif commence par `%`. */
    private const LEADING_WILDCARD = "/(?:\\blike\\s+|\\s~~\\*?\\s+)(?:_[a-z0-9]+)?'%/i";

    /**
     * @param ?string $sql     l'instruction jugée : sert seulement à vérifier qu'un nom de table du plan MySQL/MariaDB (qui peut être un
     *                         alias) est bien une table avant de suggérer un ANALYZE
     * @param bool    $limited l'instruction porte un LIMIT que le plan ne montre pas (MySQL, MariaDB) : pas de `large_sort`
     *
     * @return list<PlanFinding>
     */
    public function analyze(QueryPlan $plan, ?Engine $engine = null, ?string $sql = null, bool $limited = false): array
    {
        $findings = [];
        $stale = [];
        $this->walk($plan->root, $engine, $sql, $limited, false, $findings, $stale);

        return $findings;
    }

    /**
     * @param list<PlanFinding>  $findings
     * @param array<string,true> $stale
     */
    private function walk(PlanNode $node, ?Engine $engine, ?string $sql, bool $limited, bool $underLimit, array &$findings, array &$stale): void
    {
        if ($node->fullScan && null !== $node->rows && $node->rows >= self::FULL_SCAN_ROWS) {
            $rule = null !== $node->condition && 1 === preg_match(self::LEADING_WILDCARD, $node->condition) ? 'leading_wildcard' : 'full_scan';
            $findings[] = new PlanFinding($rule, $node->relation, $node->rows, $node->condition);
        }
        if (PlanKind::Sort === $node->kind && !$limited && !$underLimit && null !== $node->rows && $node->rows >= self::FULL_SCAN_ROWS) {
            $findings[] = new PlanFinding('large_sort', self::firstRelation($node), $node->rows, null);
        }
        if ($node->sortsOnDisk) {
            $findings[] = new PlanFinding('sort_on_disk', null, $node->rows, null, $node->actualRows);
        }
        if (null !== $node->relation && !isset($stale[$node->relation]) && self::isFarOff($node->rows, $node->actualRows)) {
            $stale[$node->relation] = true;
            $findings[] = new PlanFinding('stale_estimate', $node->relation, $node->rows, $node->condition, $node->actualRows, self::suggestion($node->relation, $engine, $sql));
        }
        foreach ($node->children as $child) {
            $this->walk($child, $engine, $sql, $limited, PlanKind::Limit === $node->kind, $findings, $stale);
        }
    }

    private static function firstRelation(PlanNode $node): ?string
    {
        if (null !== $node->relation) {
            return $node->relation;
        }
        foreach ($node->children as $child) {
            $relation = self::firstRelation($child);
            if (null !== $relation) {
                return $relation;
            }
        }

        return null;
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
