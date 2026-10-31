<?php

declare(strict_types=1);

namespace App\Security;

use App\Crypto\KeyDerivation;
use App\Repository\UserRepository;
use App\Security\Exception\InvalidCredentialsException;
use App\Security\Exception\SecondFactorRequiredException;

/**
 * Connexion : vérifie le hash d'authentification, puis dérive la clé de chiffrement depuis le mot de passe.
 * Les deux dérivations sont indépendantes (sels distincts) ; la clé n'est jamais stockée.
 *
 * Un login inconnu et un mauvais mot de passe donnent la même erreur et coûtent le même temps de calcul,
 * pour ne pas révéler quels comptes existent. Le plafonnement des tentatives relève de l'adaptateur web.
 */
final readonly class LoginService
{
    // Haché pour rien quand le login est inconnu : égalise le temps de réponse avec un vrai compte.
    private const TIMING_DECOY = 'timing-decoy-password';

    public function __construct(
        private AuthPasswordHasher $hasher,
        private KeyDerivation $derivation,
        private UserRepository $users,
    ) {
    }

    public function authenticate(string $login, #[\SensitiveParameter] string $password): LoginResult
    {
        $user = $this->users->findByLogin($login);
        if (null === $user) {
            $this->hasher->hash(self::TIMING_DECOY);

            throw InvalidCredentialsException::create();
        }

        if (!$this->hasher->verify($user->getPasswordHash(), $password)) {
            throw InvalidCredentialsException::create();
        }

        if ($user->isTotpEnabled()) {
            throw SecondFactorRequiredException::create();
        }

        if ($this->hasher->needsRehash($user->getPasswordHash())) {
            $user->rehashPassword($this->hasher->hash($password));
            $this->users->save($user);
        }

        return new LoginResult($user, $this->derivation->derive($password, $user->getKdfParameters()));
    }
}
