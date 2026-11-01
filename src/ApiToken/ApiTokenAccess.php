<?php

declare(strict_types=1);

namespace App\ApiToken;

use App\Crypto\DerivedKey;
use App\Entity\ApiToken;
use App\Entity\User;

/**
 * Ce qu'un jeton valide donne à la requête en cours : le compte, sa clé de chiffrement (à effacer en fin de
 * requête) et le jeton lui-même, qui dit quelles connexions sont permises.
 */
final readonly class ApiTokenAccess
{
    public function __construct(
        public User $user,
        public DerivedKey $key,
        public ApiToken $token,
    ) {
    }
}
