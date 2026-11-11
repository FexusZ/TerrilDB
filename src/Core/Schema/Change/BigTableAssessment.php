<?php

declare(strict_types=1);

namespace App\Core\Schema\Change;

/**
 * Le verdict sur la taille d'une table : le niveau, l'estimation du moteur (nulle si le moteur n'en donne pas : alors aucun
 * avertissement, et l'interface le dit) et les seuils en vigueur (0 = désactivé).
 */
final readonly class BigTableAssessment
{
    public function __construct(
        public BigTableLevel $level,
        public ?int $estimatedRows,
        public int $warnAt,
        public int $confirmAt,
    ) {
    }
}
