<?php

declare(strict_types=1);

namespace App\Core\Optimizer;

/**
 * Ce que le plan estimé d'une écriture dit, sans qu'elle ait été exécutée : le nombre de lignes est l'estimation du
 * moteur (pour un INSERT … SELECT, les lignes lues par le SELECT), jamais une mesure.
 */
final readonly class WritePlanSummary
{
    public function __construct(
        /** `insert`, `update` ou `delete`. */
        public string $operation,
        public ?string $relation,
        public ?int $rows,
        /** L'écriture trouve ses lignes en parcourant toute la table (sans objet pour un INSERT). */
        public bool $fullScan,
        public ?string $index,
        /** Beaucoup de lignes : mieux vaut traiter par lots. */
        public bool $batchAdvised,
        /** MySQL / MariaDB : un parcours complet pose des verrous sur toutes les lignes lues, pas seulement sur les lignes touchées. */
        public bool $locksWide,
    ) {
    }
}
