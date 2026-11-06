<?php

declare(strict_types=1);

namespace App\Entity;

/**
 * Les couleurs qu'on peut donner à une connexion pour la repérer dans la barre latérale (prod en rouge, recette en
 * vert…). Un nom, jamais une valeur CSS libre : l'interface le traduit en jeton de design, donc il suit le thème clair
 * et sombre, et rien d'arbitraire n'est injecté dans la page.
 */
enum ConnectionColor: string
{
    case Red = 'red';
    case Orange = 'orange';
    case Yellow = 'yellow';
    case Green = 'green';
    case Teal = 'teal';
    case Blue = 'blue';
    case Violet = 'violet';
    case Pink = 'pink';
}
