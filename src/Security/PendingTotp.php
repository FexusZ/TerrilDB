<?php

declare(strict_types=1);

namespace App\Security;

/**
 * Enrôlement TOTP en attente de confirmation : rien n'est enregistré tant qu'un premier code valide n'a pas
 * prouvé que l'application d'authentification est bien configurée.
 */
final readonly class PendingTotp
{
    /**
     * @param non-empty-string $secret
     */
    public function __construct(
        #[\SensitiveParameter] public string $secret,
        #[\SensitiveParameter] public string $provisioningUri,
    ) {
    }
}
