<?php

declare(strict_types=1);

namespace App\Core\Connection\Tunnel;

/**
 * Où joindre la base une fois le tunnel ouvert : le chemin d'un socket Unix (dossier `0700` partagé avec le conteneur
 * du tunnel). L'identifiant sert à le refermer.
 */
final readonly class TunnelEndpoint
{
    public function __construct(
        public string $id,
        public string $socketPath,
    ) {
    }
}
