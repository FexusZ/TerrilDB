<?php

declare(strict_types=1);

namespace App\Security;

use App\Crypto\KeyDerivation;
use App\Entity\Role;
use App\Entity\User;
use App\Repository\UserRepository;
use App\Security\Exception\AccountAlreadyExistsException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Psr\Clock\ClockInterface;

/**
 * Inscription : applique la politique de mot de passe, puis crée l'utilisateur avec deux dérivations
 * indépendantes du même mot de passe, chacune avec son propre sel — le hash d'authentification (stocké
 * pour vérifier la connexion) et les paramètres KDF (qui permettront de dériver la clé de chiffrement
 * sans que le serveur ne la conserve).
 *
 * Le premier compte d'une installation en devient l'administrateur.
 *
 * L'unicité repose sur les contraintes de la base : une vérification préalable laisserait une course
 * entre deux inscriptions simultanées.
 */
final readonly class UserRegistrar
{
    public function __construct(
        private PasswordPolicy $policy,
        private AuthPasswordHasher $hasher,
        private KeyDerivation $derivation,
        private UserRepository $users,
        private ClockInterface $clock,
    ) {
    }

    public function register(string $email, string $username, #[\SensitiveParameter] string $password): User
    {
        $this->policy->assertAcceptable($password);

        $user = new User(
            $email,
            $username,
            $this->hasher->hash($password),
            $this->derivation->newParameters(),
            \DateTimeImmutable::createFromInterface($this->clock->now()),
            0 === $this->users->count([]) ? Role::Admin : Role::Member,
        );

        try {
            $this->users->save($user);
        } catch (UniqueConstraintViolationException $e) {
            throw AccountAlreadyExistsException::create($e);
        }

        return $user;
    }
}
