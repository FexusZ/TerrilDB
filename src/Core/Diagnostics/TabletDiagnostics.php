<?php

declare(strict_types=1);

namespace App\Core\Diagnostics;

use App\Core\Diagnostics\Vitess\TabletListing;
use App\Core\Diagnostics\Vitess\VitessTablet;

/**
 * Les tablets qu'un vtgate sait viser (Vitess seulement). Sert à lire les autres thèmes tablet par tablet, car à travers vtgate
 * chaque lecture du catalogue part vers UN tablet au hasard.
 */
interface TabletDiagnostics
{
    public function status(): DiagnosticStatus;

    /**
     * @return list<VitessTablet> ceux qui ont la forme d'une cible, dans l'ordre du serveur
     */
    public function tablets(): array;

    /**
     * Les mêmes tablets, avec ceux qu'on a écartés et pourquoi (une seule lecture du topo).
     */
    public function listing(): TabletListing;
}
