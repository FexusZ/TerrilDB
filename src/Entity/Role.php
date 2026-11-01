<?php

declare(strict_types=1);

namespace App\Entity;

/**
 * Le premier compte d'une installation en est l'administrateur : il invite les autres. Un membre n'a accès qu'à
 * ses propres connexions, comme l'administrateur ; seule la gestion des invitations les distingue.
 */
enum Role: string
{
    case Admin = 'admin';
    case Member = 'member';
}
