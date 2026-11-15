<?php

declare(strict_types=1);

namespace App\Core\Diagnostics;

/**
 * Pourquoi une source de diagnostic se lit ou non. « Vide » n'est jamais un état : une source illisible le dit.
 */
enum Availability: string
{
    case Available = 'available';
    /** Le moteur (ou sa version) n'a pas cette notion. */
    case Unsupported = 'unsupported';
    /** Le compte de la connexion n'a pas le droit de lire cette source. */
    case PermissionDenied = 'permission_denied';
    /** La source existe mais est éteinte ou absente (performance_schema éteint, extension non installée…). */
    case Disabled = 'disabled';
    /** La vérification a échoué pour une cause imprévue : on ne sait pas, et on le dit (la cause n'est jamais montrée). */
    case Error = 'error';
}
