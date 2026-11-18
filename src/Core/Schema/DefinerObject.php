<?php

declare(strict_types=1);

namespace App\Core\Schema;

/**
 * Un objet qui s'exécute sous l'identité de `definer` (« compte@hôte », comme le catalogue l'écrit). `table` n'est donné que pour
 * un déclencheur : la table qui le porte.
 */
final readonly class DefinerObject
{
    public function __construct(
        public DefinerKind $kind,
        public string $name,
        public ?string $table,
        public string $definer,
    ) {
    }
}
