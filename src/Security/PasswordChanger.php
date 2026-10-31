<?php

declare(strict_types=1);

namespace App\Security;

use App\Crypto\DerivedKey;
use App\Crypto\SecretRotator;
use App\Entity\User;
use App\Repository\UserRepository;
use App\Security\Exception\InvalidCredentialsException;

/**
 * Changement de mot de passe. La clé de chiffrement dérive du mot de passe : en changer impose de rechiffrer
 * tous les secrets de l'utilisateur, tout-ou-rien (SecretRotator), puis d'enregistrer d'un bloc le nouveau hash,
 * les nouveaux paramètres de dérivation et les secrets rechiffrés.
 *
 * Aujourd'hui seul le secret TOTP est concerné ; les identifiants de connexion chiffrés rejoindront l'ensemble
 * des secrets à rechiffrer avec leur propre lot, dans la même transaction.
 *
 * La nouvelle clé est rendue pour que la session en cours reste déverrouillée sans redemander le mot de passe.
 * L'invalidation des autres sessions relève de l'adaptateur web.
 */
final readonly class PasswordChanger
{
    public function __construct(
        private PasswordPolicy $policy,
        private AuthPasswordHasher $hasher,
        private SecretRotator $rotator,
        private UserRepository $users,
    ) {
    }

    public function change(User $user, #[\SensitiveParameter] string $currentPassword, #[\SensitiveParameter] string $newPassword): DerivedKey
    {
        if (!$this->hasher->verify($user->getPasswordHash(), $currentPassword)) {
            throw InvalidCredentialsException::create();
        }
        $this->policy->assertAcceptable($newPassword);

        $totpContext = TotpSecretContext::forUser($user);
        $totpSecret = $user->getTotpSecret();
        $secrets = null === $totpSecret ? [] : [$totpContext => $totpSecret];

        $rotation = $this->rotator->rotate($currentPassword, $user->getKdfParameters(), $newPassword, $secrets);

        try {
            $user->changePassword($this->hasher->hash($newPassword), $rotation->parameters, $rotation->secrets[$totpContext] ?? null);
            $this->users->save($user);
        } catch (\Throwable $e) {
            $rotation->key->wipe();

            throw $e;
        }

        return $rotation->key;
    }
}
