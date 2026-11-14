<?php

declare(strict_types=1);

namespace App\Core\Connection\Tunnel;

/**
 * Ce que le bastion publie de sa clé d'hôte : l'empreinte SHA-256 (à faire confirmer par l'utilisateur, jamais à accepter
 * seule) et le type de clé. La clé elle-même ne sort pas du conteneur du tunnel.
 */
final readonly class HostKey
{
    public const TYPES = ['ed25519', 'ecdsa', 'rsa'];

    public function __construct(
        public string $fingerprint,
        public string $type,
    ) {
    }
}
