<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\User;

/**
 * Contexte authentifié du secret TOTP chiffré : il lie le chiffré à son utilisateur et sert aussi
 * d'identifiant lors de la rotation au changement de mot de passe (SecretRotator).
 */
final readonly class TotpSecretContext
{
    public static function forUser(User $user): string
    {
        return 'totp:'.$user->getId()->toRfc4122();
    }
}
