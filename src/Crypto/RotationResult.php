<?php

declare(strict_types=1);

namespace App\Crypto;

/**
 * Résultat d'un changement de mot de passe : à persister d'un bloc (paramètres KDF + secrets rechiffrés).
 * La clé neuve est rendue pour que la session en cours reste déverrouillée sans redemander le mot de passe.
 */
final readonly class RotationResult
{
    /**
     * @param array<array-key, string> $secrets chiffrés indexés par le même identifiant qu'en entrée
     */
    public function __construct(
        public KdfParameters $parameters,
        public DerivedKey $key,
        public array $secrets,
    ) {
    }
}
