<?php

declare(strict_types=1);

namespace App\Core\Schema\Change;

/**
 * Ce que la taille estimée d'une table demande avant de la modifier : rien, un avertissement, ou en plus de retaper son nom.
 */
enum BigTableLevel: string
{
    case None = 'none';
    case Warning = 'warning';
    case ConfirmName = 'confirm_name';
}
