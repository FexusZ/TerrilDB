<?php

declare(strict_types=1);

namespace App\Core\Optimizer;

/**
 * Un constat sur un plan d'exécution : un gros parcours complet, et, pour un plan mesuré, une estimation très éloignée du
 * réel ou un tri sur disque.
 *
 * @param ?int    $rows       lignes estimées par le moteur
 * @param ?int    $actualRows lignes réellement rendues (plan mesuré seulement)
 * @param ?string $suggestion une instruction SQL À LIRE (jamais exécutée par TerrilDB)
 */
final readonly class PlanFinding
{
    public function __construct(
        public string $rule,
        public ?string $relation,
        public ?int $rows,
        public ?string $condition,
        public ?int $actualRows = null,
        public ?string $suggestion = null,
    ) {
    }
}
