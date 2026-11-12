<?php

declare(strict_types=1);

namespace App\Security;

use App\Crypto\DerivedKey;
use App\Crypto\KeyDerivation;
use App\Crypto\SecretCipher;
use App\Entity\User;
use App\Repository\UserRepository;
use App\Security\Exception\InvalidCredentialsException;
use App\Security\Exception\InvalidSecondFactorException;
use App\Security\Exception\SecondFactorRequiredException;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;

/**
 * Connexion : vérifie le hash d'authentification, dérive la clé de chiffrement depuis le mot de passe, puis,
 * si le compte a un second facteur, vérifie le code TOTP (ou un code de récupération, à usage unique).
 * Les deux dérivations du mot de passe sont indépendantes (sels distincts) ; la clé n'est jamais stockée.
 *
 * Le secret TOTP est chiffré par la clé de l'utilisateur : c'est pourquoi le mot de passe est requis avant le
 * code. Une période TOTP acceptée est mémorisée et ne sert qu'une fois ; le verrou optimiste du compte fait
 * échouer la seconde de deux connexions simultanées présentant le même code.
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
        private TotpService $totp,
        private SecretCipher $cipher,
        private RecoveryCodeService $recoveryCodes,
        private ClockInterface $clock,
        private LoggerInterface $logger,
    ) {
    }

    public function authenticate(string $login, #[\SensitiveParameter] string $password, #[\SensitiveParameter] ?string $secondFactor = null): LoginResult
    {
        $user = $this->users->findByLogin($login);
        if (null === $user) {
            $this->hasher->hash(self::TIMING_DECOY);

            throw InvalidCredentialsException::create();
        }

        if (!$this->hasher->verify($user->getPasswordHash(), $password)) {
            throw InvalidCredentialsException::create();
        }

        // Un compte désactivé répond comme un mauvais mot de passe (après le même calcul) : rien ne dit qu'il existe.
        if ($user->isDisabled()) {
            throw InvalidCredentialsException::create();
        }

        if ($user->isTotpEnabled() && '' === trim($secondFactor ?? '')) {
            throw SecondFactorRequiredException::create();
        }

        $key = $this->derivation->derive($password, $user->getKdfParameters());

        try {
            $usedRecoveryCode = $user->isTotpEnabled() && $this->checkSecondFactor($user, $key, $secondFactor ?? '');

            if ($this->hasher->needsRehash($user->getPasswordHash())) {
                $user->rehashPassword($this->hasher->hash($password));
            }
            // Un seul enregistrement : période TOTP consommée (ou code de récupération) et hash mis à niveau.
            $this->users->save($user);
        } catch (\Throwable $e) {
            $key->wipe();

            throw $e;
        }

        $this->recordLastLogin($user);

        return new LoginResult($user, $key, $usedRecoveryCode);
    }

    /**
     * La date est informative : jamais une raison de refuser la connexion ni de heurter le verrou de version.
     */
    private function recordLastLogin(User $user): void
    {
        try {
            $this->users->recordLogin($user, \DateTimeImmutable::createFromInterface($this->clock->now()));
        } catch (\Throwable $failure) {
            $this->logger->warning('Last login date not recorded.', ['reason' => $failure::class]);
        }
    }

    /**
     * Applique le second facteur à l'utilisateur (sans l'enregistrer).
     *
     * @return bool true si un code de récupération a été utilisé
     */
    private function checkSecondFactor(User $user, DerivedKey $key, string $code): bool
    {
        $encryptedSecret = $user->getTotpSecret() ?? throw InvalidSecondFactorException::create();
        $secret = $this->cipher->decrypt($encryptedSecret, $key, TotpSecretContext::forUser($user));

        try {
            if ('' !== $secret) {
                $step = $this->totp->verify($secret, $code, $user->getTotpLastStep(), $this->clock->now()->getTimestamp());
                if (null !== $step) {
                    $user->recordTotpStep($step);

                    return false;
                }
            }
        } finally {
            sodium_memzero($secret);
        }

        $remaining = $this->recoveryCodes->consume($code, $user->getRecoveryCodeHashes());
        if (null === $remaining) {
            throw InvalidSecondFactorException::create();
        }
        $user->replaceRecoveryCodeHashes($remaining);

        return true;
    }
}
