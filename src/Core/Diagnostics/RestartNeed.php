<?php

declare(strict_types=1);

namespace App\Core\Diagnostics;

/**
 * Ce qu'un redémarrage change pour une variable. Absent = le moteur ne le dit pas (jamais « aucun besoin »).
 */
enum RestartNeed: string
{
    /** Le serveur ne la relit qu'au démarrage : la changer demande un redémarrage. */
    case Required = 'required';
    /** Une nouvelle valeur est déjà posée mais le serveur tourne encore avec l'ancienne. */
    case Pending = 'pending';
}
