<?php

declare(strict_types=1);

namespace App\Core\Schema;

/**
 * Les objets d'un schéma MySQL / MariaDB qui s'exécutent sous l'identité de leur créateur.
 */
enum DefinerKind: string
{
    case View = 'view';
    case Procedure = 'procedure';
    case Function = 'function';
    case Trigger = 'trigger';
    case Event = 'event';
}
