<?php

declare(strict_types=1);

namespace App\Core\Guard;

enum Decision
{
    case Allowed;
    /** Autorisé, mais seulement après une confirmation explicite de l'utilisateur. */
    case NeedsConfirmation;
    case Refused;
}
