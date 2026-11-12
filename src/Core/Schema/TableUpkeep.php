<?php

declare(strict_types=1);

namespace App\Core\Schema;

/**
 * Ce que le moteur compte sur l'entretien d'une table, tel qu'il le dit (jamais un COUNT) : lignes vivantes et mortes, lignes
 * modifiées depuis la dernière analyse, dernières dates d'analyse et de nettoyage, tailles en octets et place libre dans le fichier
 * de la table. Chaque champ est nul quand le moteur ne le donne pas.
 */
final readonly class TableUpkeep
{
    public function __construct(
        public string $table,
        public ?int $liveRows = null,
        public ?int $deadRows = null,
        public ?int $modifiedSinceAnalyze = null,
        public ?string $lastAnalyze = null,
        public ?string $lastVacuum = null,
        public ?int $dataBytes = null,
        public ?int $indexBytes = null,
        public ?int $freeBytes = null,
    ) {
    }
}
