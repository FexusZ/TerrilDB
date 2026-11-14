<?php

declare(strict_types=1);

namespace App\Core\Schema\Change;

use App\Core\Diagnostics\TableHolders;

/**
 * Le plan d'un changement et l'empreinte de la table AVANT (`fingerprint`), avec `stale` : l'empreinte que le client avait n'est
 * plus la bonne. `holders` : les sessions qui tiennent la table et feraient attendre l'application (nul quand le moteur n'a pas
 * de diagnostic ou qu'il n'y a rien à appliquer) ; c'est un avertissement, jamais un blocage.
 */
final readonly class ChangePreview
{
    public function __construct(
        public ChangePlan $plan,
        public string $fingerprint,
        public bool $stale,
        public ?TableHolders $holders = null,
    ) {
    }
}
