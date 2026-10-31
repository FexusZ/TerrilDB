<?php

declare(strict_types=1);

namespace App\Security;

use App\Connection\ConnectionSecretContext;
use App\Crypto\DerivedKey;
use App\Crypto\SecretRotator;
use App\Entity\User;
use App\Repository\DatabaseConnectionRepository;
use App\Repository\UserRepository;
use App\Security\Exception\InvalidCredentialsException;

/**
 * Changement de mot de passe. La clé de chiffrement dérive du mot de passe : en changer impose de rechiffrer
 * tous les secrets de l'utilisateur, tout-ou-rien (SecretRotator), puis d'enregistrer d'un bloc le nouveau hash,
 * les nouveaux paramètres de dérivation et les secrets rechiffrés.
 *
 * Les secrets à rechiffrer sont le secret TOTP et les paramètres de chaque connexion de l'utilisateur. Une seule
 * connexion illisible suffit à refuser le changement : mieux vaut un refus qu'une connexion perdue en silence.
 * Les connexions sont chargées par le dépôt (donc gérées par l'ORM) : le `flush` du compte les enregistre dans
 * la même transaction que le nouveau hash.
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
        private DatabaseConnectionRepository $connections,
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
        $connections = $this->connections->findAllOwnedBy($user);
        foreach ($connections as $connection) {
            $secrets[ConnectionSecretContext::forConnection($connection)] = $connection->getEncryptedParameters();
        }

        $rotation = $this->rotator->rotate($currentPassword, $user->getKdfParameters(), $newPassword, $secrets);

        try {
            $user->changePassword($this->hasher->hash($newPassword), $rotation->parameters, $rotation->secrets[$totpContext] ?? null);
            foreach ($connections as $connection) {
                $connection->rotateEncryptedParameters($rotation->secrets[ConnectionSecretContext::forConnection($connection)]);
            }
            $this->users->save($user);
        } catch (\Throwable $e) {
            $rotation->key->wipe();

            throw $e;
        }

        return $rotation->key;
    }
}
