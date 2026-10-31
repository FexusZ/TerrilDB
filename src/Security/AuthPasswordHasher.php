<?php

declare(strict_types=1);

namespace App\Security;

use Symfony\Component\PasswordHasher\Exception\InvalidPasswordException;
use Symfony\Component\PasswordHasher\Hasher\SodiumPasswordHasher;
use Symfony\Component\PasswordHasher\PasswordHasherInterface;

/**
 * Hash d'authentification du mot de passe (Argon2id). Il porte son propre sel aléatoire, sans lien avec
 * celui de la dérivation de clé (Crypto\KeyDerivation) : le hash stocké ne donne aucun accès à la clé de
 * chiffrement des identifiants.
 *
 * Seul Argon2id est accepté : le hasher de Symfony valide aussi, pour faciliter les migrations, des hash
 * bcrypt ou Argon2i, ce qui n'a pas de sens ici.
 */
final readonly class AuthPasswordHasher implements PasswordHasherInterface
{
    private const PREFIX = '$argon2id$';

    private SodiumPasswordHasher $hasher;

    public function __construct(
        int $opsLimit = \SODIUM_CRYPTO_PWHASH_OPSLIMIT_MODERATE,
        int $memLimit = \SODIUM_CRYPTO_PWHASH_MEMLIMIT_MODERATE,
    ) {
        $this->hasher = new SodiumPasswordHasher($opsLimit, $memLimit);
    }

    public function hash(#[\SensitiveParameter] string $plainPassword): string
    {
        if ('' === $plainPassword) {
            throw new InvalidPasswordException();
        }

        return $this->hasher->hash($plainPassword);
    }

    public function verify(string $hashedPassword, #[\SensitiveParameter] string $plainPassword): bool
    {
        return str_starts_with($hashedPassword, self::PREFIX)
            && $this->hasher->verify($hashedPassword, $plainPassword);
    }

    public function needsRehash(string $hashedPassword): bool
    {
        return !str_starts_with($hashedPassword, self::PREFIX) || $this->hasher->needsRehash($hashedPassword);
    }
}
