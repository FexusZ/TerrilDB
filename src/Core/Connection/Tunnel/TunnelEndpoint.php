<?php

declare(strict_types=1);

namespace App\Core\Connection\Tunnel;

/**
 * Où joindre la base une fois le tunnel ouvert : le chemin d'un socket Unix (dossier `0700` partagé avec le conteneur
 * du tunnel). L'identifiant sert à le refermer. `reused` : le tunnel existait déjà (partagé avec un autre usage) ; en cas
 * de doute, vrai : on ne referme que ce qu'on est sûr d'avoir ouvert.
 */
final readonly class TunnelEndpoint
{
    public function __construct(
        public string $id,
        public string $socketPath,
        public bool $reused = true,
    ) {
    }
}
