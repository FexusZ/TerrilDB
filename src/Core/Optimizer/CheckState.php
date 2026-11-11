<?php

declare(strict_types=1);

namespace App\Core\Optimizer;

/**
 * Pourquoi une vérification n'a pas rendu de verdict.
 */
enum CheckState: string
{
    /** La source n'est pas lisible (compteurs éteints, droit manquant). */
    case Unavailable = 'unavailable';
    /** La collecte est trop récente pour juger. */
    case TooShort = 'too_short';
}
