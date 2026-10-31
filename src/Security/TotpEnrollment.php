<?php

declare(strict_types=1);

namespace App\Security;

use App\Crypto\DerivedKey;
use App\Crypto\SecretCipher;
use App\Entity\User;
use App\Repository\UserRepository;
use App\Security\Exception\InvalidCredentialsException;
use App\Security\Exception\InvalidSecondFactorException;
use App\Security\Exception\TotpAlreadyEnabledException;
use Psr\Clock\ClockInterface;

/**
 * Activation et désactivation du second facteur.
 *
 * Le secret n'est enregistré qu'après confirmation par un premier code valide, chiffré par la clé dérivée du
 * mot de passe (le serveur seul ne peut pas le lire). Le code de confirmation compte comme utilisé : il ne peut
 * pas être rejoué à la connexion suivante. Remplacer un second facteur passe par la désactivation, qui exige
 * le mot de passe — une session volée ne suffit pas à le changer.
 */
final readonly class TotpEnrollment
{
    public function __construct(
        private TotpService $totp,
        private SecretCipher $cipher,
        private RecoveryCodeService $recoveryCodes,
        private AuthPasswordHasher $hasher,
        private UserRepository $users,
        private ClockInterface $clock,
    ) {
    }

    public function begin(User $user): PendingTotp
    {
        $this->assertNotEnabled($user);

        $secret = $this->totp->generateSecret();

        return new PendingTotp($secret, $this->totp->provisioningUri($secret, $user->getUsername()));
    }

    public function confirm(User $user, DerivedKey $key, #[\SensitiveParameter] string $secret, string $code): NewRecoveryCodes
    {
        $this->assertNotEnabled($user);

        if ('' === $secret) {
            throw InvalidSecondFactorException::create();
        }
        $step = $this->totp->verify($secret, $code, 0, $this->clock->now()->getTimestamp());
        if (null === $step) {
            throw InvalidSecondFactorException::create();
        }

        $recoveryCodes = $this->recoveryCodes->generate();
        $user->enableTotp($this->cipher->encrypt($secret, $key, TotpSecretContext::forUser($user)), $recoveryCodes->hashes);
        $user->recordTotpStep($step);
        $this->users->save($user);

        return $recoveryCodes;
    }

    public function disable(User $user, #[\SensitiveParameter] string $password): void
    {
        if (!$this->hasher->verify($user->getPasswordHash(), $password)) {
            throw InvalidCredentialsException::create();
        }

        $user->disableTotp();
        $this->users->save($user);
    }

    private function assertNotEnabled(User $user): void
    {
        if ($user->isTotpEnabled()) {
            throw TotpAlreadyEnabledException::create();
        }
    }
}
