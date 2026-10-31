<?php

declare(strict_types=1);

namespace App\Security;

use App\Crypto\DerivedKey;
use App\Entity\User;

/**
 * Connexion réussie. L'appelant devient propriétaire de la clé : il la confie au coffre de session
 * (qui l'efface quand il l'oublie) ou l'efface lui-même.
 *
 * $usedRecoveryCode permet d'avertir l'utilisateur qu'un de ses codes de récupération vient d'être consommé.
 */
final readonly class LoginResult
{
    public function __construct(
        public User $user,
        public DerivedKey $key,
        public bool $usedRecoveryCode = false,
    ) {
    }
}
