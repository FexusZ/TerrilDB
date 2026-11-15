<?php

declare(strict_types=1);

namespace App\Core\Data;

/**
 * Une alerte de l'aperçu, jamais un blocage. `code` est stable (`not_transactional` : le moteur de stockage n'annule pas, `value`
 * = son nom ; `triggers` : la table a des déclencheurs, `value` = leur nombre) ; le texte est celui de l'interface.
 */
final readonly class DataWarning
{
    public function __construct(public string $code, public string|int|null $value = null)
    {
    }
}
