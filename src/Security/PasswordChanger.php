<?php

declare(strict_types=1);

namespace App\Security;

use App\ApiToken\ApiTokenService;
use App\Connection\ConnectionSecretContext;
use App\Crypto\DerivedKey;
use App\Crypto\RotationResult;
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
 * Les jetons d'API emportent une copie de l'ancienne clé : ils sont supprimés dans la même transaction.
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
        private ApiTokenService $apiTokens,
    ) {
    }

    public function change(User $user, #[\SensitiveParameter] string $currentPassword, #[\SensitiveParameter] string $newPassword): DerivedKey
    {
        if (!$this->hasher->verify($user->getPasswordHash(), $currentPassword)) {
            throw InvalidCredentialsException::create();
        }
        $this->policy->assertAcceptable($newPassword);

        return $this->apply($user, $newPassword, fn (array $secrets): RotationResult => $this->rotator->rotate($currentPassword, $user->getKdfParameters(), $newPassword, $secrets));
    }

    /**
     * Mot de passe oublié : l'ancienne clé a été retrouvée par la phrase de récupération au lieu d'être dérivée de
     * l'ancien mot de passe. Même rotation tout-ou-rien ; l'ancienne clé reste à l'appelant, qui l'efface.
     */
    public function resetWithKey(User $user, DerivedKey $oldKey, #[\SensitiveParameter] string $newPassword): DerivedKey
    {
        $this->policy->assertAcceptable($newPassword);

        return $this->apply($user, $newPassword, fn (array $secrets): RotationResult => $this->rotator->rotateWithKey($oldKey, $newPassword, $secrets));
    }

    /**
     * @param \Closure(array<array-key, string>): RotationResult $rotate
     */
    private function apply(User $user, #[\SensitiveParameter] string $newPassword, \Closure $rotate): DerivedKey
    {
        $totpContext = TotpSecretContext::forUser($user);
        $totpSecret = $user->getTotpSecret();
        $secrets = null === $totpSecret ? [] : [$totpContext => $totpSecret];
        $connections = $this->connections->findAllOwnedBy($user);
        foreach ($connections as $connection) {
            $secrets[ConnectionSecretContext::forConnection($connection)] = $connection->getEncryptedParameters();
        }

        $rotation = $rotate($secrets);

        try {
            $user->changePassword($this->hasher->hash($newPassword), $rotation->parameters, $rotation->secrets[$totpContext] ?? null);
            foreach ($connections as $connection) {
                $connection->rotateEncryptedParameters($rotation->secrets[ConnectionSecretContext::forConnection($connection)]);
            }
            // Une clé enveloppée dans un jeton est celle d'avant : le jeton ne servirait plus à rien, il disparaît.
            $this->apiTokens->revokeAll($user);
            $this->users->save($user);
        } catch (\Throwable $e) {
            $rotation->key->wipe();

            throw $e;
        }

        return $rotation->key;
    }
}
