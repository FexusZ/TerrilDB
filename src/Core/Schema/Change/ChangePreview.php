<?php

declare(strict_types=1);

namespace App\Core\Schema\Change;

/**
 * Le plan d'un changement et l'empreinte de la table AVANT (`fingerprint`), avec `stale` : l'empreinte que le client avait n'est
 * plus la bonne ; `bigTable` dit ce que la taille estimée de la table demande avant d'appliquer.
 */
final readonly class ChangePreview
{
    public function __construct(
        public ChangePlan $plan,
        public string $fingerprint,
        public bool $stale,
        public BigTableAssessment $bigTable,
    ) {
    }
}
