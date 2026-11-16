<?php

declare(strict_types=1);

namespace App\Core\Driver;

/**
 * Ce que la chaîne de version du serveur révèle de sa famille, au-delà du moteur déclaré : un serveur déclaré MySQL peut
 * être un MariaDB ou un Vitess.
 */
enum ServerFlavour: string
{
    case Standard = 'standard';
    case MariaDb = 'mariadb';
    case Vitess = 'vitess';
}
