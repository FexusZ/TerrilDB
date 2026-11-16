<?php

declare(strict_types=1);

namespace App\Core\Diagnostics\Health;

/**
 * Un repère, pas un verdict : « avertissement » dit qu'une valeur dépasse le seuil documenté, à valider sur la charge réelle.
 */
enum HealthLevel: string
{
    case Ok = 'ok';
    case Warning = 'warning';
    /** Pas de mesure : compteur absent sur cette version, remis à zéro ou intervalle sans activité. */
    case Unknown = 'unknown';
}
