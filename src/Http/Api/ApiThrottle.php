<?php

declare(strict_types=1);

namespace App\Http\Api;

use App\Http\Json\TooManyAttemptsException;
use Symfony\Component\RateLimiter\RateLimit;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Deux plafonds pour l'API : les échecs d'authentification par adresse (un jeton valide ne se devine pas, mais
 * chaque essai coûte une requête), et le nombre de requêtes d'un même jeton par minute.
 */
final readonly class ApiThrottle
{
    public function __construct(
        private RateLimiterFactoryInterface $apiFailuresLimiter,
        private RateLimiterFactoryInterface $apiTokenLimiter,
    ) {
    }

    /**
     * @throws TooManyAttemptsException si l'adresse a déjà trop échoué
     */
    public function assertNotBlocked(?string $ip): void
    {
        // consume(0) lit l'état sans rien compter ; il reste « accepté » à la limite exacte, d'où le test sur le reste.
        $state = $this->apiFailuresLimiter->create($ip ?? 'unknown')->consume(0);
        if ($state->getRemainingTokens() < 1) {
            throw self::tooMany($state);
        }
    }

    public function failed(?string $ip): void
    {
        $this->apiFailuresLimiter->create($ip ?? 'unknown')->consume();
    }

    /**
     * @throws TooManyAttemptsException si le jeton a épuisé son budget de la minute
     */
    public function used(Uuid $tokenId): void
    {
        $state = $this->apiTokenLimiter->create($tokenId->toRfc4122())->consume();
        if (!$state->isAccepted()) {
            throw self::tooMany($state);
        }
    }

    private static function tooMany(RateLimit $state): TooManyAttemptsException
    {
        return new TooManyAttemptsException(max(1, $state->getRetryAfter()->getTimestamp() - time()));
    }
}
