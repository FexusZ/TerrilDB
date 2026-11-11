<?php

declare(strict_types=1);

namespace App\Http\Web\Security;

use App\Http\Json\TooManyAttemptsException;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

/**
 * Plafonne les tentatives de connexion : par adresse (contre quelqu'un qui essaie beaucoup de comptes) et par
 * compte (contre quelqu'un qui devine un mot de passe ou un code TOTP). Le compteur du compte est remis à zéro
 * par une connexion réussie. Un compte visé peut donc être temporairement bloqué par un tiers : c'est le prix
 * de la protection de son mot de passe.
 */
final readonly class LoginThrottle
{
    public function __construct(
        private RateLimiterFactoryInterface $loginIpLimiter,
        private RateLimiterFactoryInterface $loginAccountLimiter,
        private LoginLockout $lockout,
    ) {
    }

    /**
     * @throws TooManyAttemptsException
     */
    public function hit(?string $ip, string $login): void
    {
        $limits = [
            $this->loginIpLimiter->create($ip ?? 'unknown')->consume(),
            $this->loginAccountLimiter->create(self::accountKey($login))->consume(),
        ];

        foreach ($limits as $limit) {
            if (!$limit->isAccepted()) {
                throw new TooManyAttemptsException(max(1, $limit->getRetryAfter()->getTimestamp() - time()));
            }
        }
    }

    /**
     * Avant une vraie connexion (ou une récupération) : refuse un couple adresse + compte bloqué ou une adresse bannie, et
     * garde le plafond par adresse. Pas de plafond par compte ici : il laissait un tiers verrouiller le compte de tout
     * le monde ; le blocage vise désormais le couple adresse + compte (voir LoginLockout).
     *
     * @throws TooManyAttemptsException
     */
    public function hitLogin(?string $ip, string $login): void
    {
        $this->lockout->check($ip, $login);

        $limit = $this->loginIpLimiter->create($ip ?? 'unknown')->consume();
        if (!$limit->isAccepted()) {
            throw new TooManyAttemptsException(max(1, $limit->getRetryAfter()->getTimestamp() - time()));
        }
    }

    /**
     * Un échec de connexion : rend ce qu'il déclenche (blocage du couple, bannissement de l'adresse), à journaliser.
     */
    public function failed(?string $ip, string $login): ?LockoutOutcome
    {
        return $this->lockout->failed($ip, $login);
    }

    public function succeeded(string $login, ?string $ip = null): void
    {
        $this->loginAccountLimiter->create(self::accountKey($login))->reset();
        if (null !== $ip) {
            $this->lockout->succeeded($ip, $login);
        }
    }

    private static function accountKey(string $login): string
    {
        return hash('sha256', mb_strtolower(trim($login)));
    }
}
