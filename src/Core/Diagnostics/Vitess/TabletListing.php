<?php

declare(strict_types=1);

namespace App\Core\Diagnostics\Vitess;

/**
 * Ce que `SHOW VITESS_TABLETS` rend : les tablets qu'on sait viser, et ceux qu'on a écartés (avec la raison).
 */
final readonly class TabletListing
{
    /**
     * @param list<VitessTablet>  $tablets ceux qui ont la forme d'une cible, dans l'ordre du serveur
     * @param list<SkippedTablet> $skipped les autres
     */
    public function __construct(
        public array $tablets,
        public array $skipped = [],
    ) {
    }
}
