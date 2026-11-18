<?php

declare(strict_types=1);

namespace App\Driver\MySql;

use App\Core\Connection\Engine;

/**
 * Les mots `REPLICA` n'existent qu'à partir de MySQL 8.0.22 et de MariaDB 10.5.1 ; avant, seuls les anciens mots (SLAVE, MASTER)
 * existent, et MySQL 8.4 les a supprimés. TerrilDB n'envoie que la syntaxe courante : un serveur plus ancien est dit non pris en
 * charge, jamais interrogé avec une syntaxe retirée des versions que l'on cible.
 */
final class ReplicaSyntax
{
    public static function exists(Engine $engine, string $version): bool
    {
        // Un MariaDB ancien annonce « 5.5.5-10.x.y-MariaDB » pour tromper les clients de réplication : la vraie version suit.
        $version = preg_replace('/^5\.5\.5-(?=\d)/', '', $version) ?? $version;
        if (1 !== preg_match('/^(\d+)\.(\d+)\.(\d+)/', $version, $m)) {
            return false;
        }
        $parts = [(int) $m[1], (int) $m[2], (int) $m[3]];

        return $parts >= (Engine::MariaDb === $engine ? [10, 5, 1] : [8, 0, 22]);
    }
}
