<?php

declare(strict_types=1);

namespace App\Crypto;

use App\Crypto\Exception\EmptyPasswordException;
use App\Crypto\Exception\KeyDerivationFailedException;

/**
 * Dérive la clé de chiffrement d'un utilisateur depuis son mot de passe (Argon2id).
 * Le coût configuré ne s'applique qu'aux nouveaux paramètres : une dérivation relit toujours
 * ceux stockés avec l'utilisateur, ce qui permet de relever le coût sans casser les anciens comptes.
 */
final readonly class KeyDerivation
{
    public function __construct(
        private int $opsLimit = \SODIUM_CRYPTO_PWHASH_OPSLIMIT_MODERATE,
        private int $memLimit = \SODIUM_CRYPTO_PWHASH_MEMLIMIT_MODERATE,
    ) {
    }

    public function newParameters(): KdfParameters
    {
        return new KdfParameters($this->opsLimit, $this->memLimit, random_bytes(KdfParameters::SALT_BYTES));
    }

    public function derive(#[\SensitiveParameter] string $password, KdfParameters $parameters): DerivedKey
    {
        if ('' === $password) {
            throw new EmptyPasswordException('The password must not be empty.');
        }

        try {
            $bytes = sodium_crypto_pwhash(
                DerivedKey::LENGTH,
                $password,
                $parameters->salt,
                $parameters->opsLimit,
                $parameters->memLimit,
                \SODIUM_CRYPTO_PWHASH_ALG_ARGON2ID13,
            );
        } catch (\SodiumException $e) {
            throw new KeyDerivationFailedException('Key derivation failed.', previous: $e);
        }

        $key = new DerivedKey($bytes);
        sodium_memzero($bytes);

        return $key;
    }
}
