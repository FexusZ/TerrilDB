<?php

declare(strict_types=1);

namespace App\Http\Web\Security;

use App\Http\Web\TooManyAttemptsException;
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

    public function succeeded(string $login): void
    {
        $this->loginAccountLimiter->create(self::accountKey($login))->reset();
    }

    private static function accountKey(string $login): string
    {
        return hash('sha256', mb_strtolower(trim($login)));
    }
}
