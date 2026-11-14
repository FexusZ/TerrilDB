<?php

declare(strict_types=1);

namespace App\Connection;

use App\Core\Connection\Tunnel\TooManyTunnelsException;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Plafond de tunnels simultanés PAR COMPTE (les plafonds du relais sont globaux : sans cela, un compte occuperait les places
 * de tous). Le relais ferme un tunnel après 5 minutes sans nouvelle demande : on en tient la comptabilité avec la même
 * échéance, renouvelée à chaque usage. Un tunnel déjà compté ne compte pas deux fois. Comptabilité approchée (cache, sans
 * verrou) : un dépassement d'une unité en cas de requêtes simultanées reste possible, le relais garde ses plafonds globaux.
 */
final readonly class TunnelQuota
{
    public const MAX_PER_ACCOUNT = 5;
    private const LIFETIME_SECONDS = 300;

    public function __construct(
        private CacheItemPoolInterface $cache,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @throws TooManyTunnelsException
     */
    public function admit(Uuid $user, string $tunnelId): void
    {
        $item = $this->cache->getItem($this->key($user));
        $now = $this->clock->now()->getTimestamp();
        $held = $this->alive($item->get(), $now);
        if (!isset($held[$tunnelId]) && \count($held) >= self::MAX_PER_ACCOUNT) {
            throw TooManyTunnelsException::create();
        }
        $held[$tunnelId] = $now + self::LIFETIME_SECONDS;
        $this->cache->save($item->set($held)->expiresAfter(self::LIFETIME_SECONDS));
    }

    public function release(Uuid $user, string $tunnelId): void
    {
        $item = $this->cache->getItem($this->key($user));
        $held = $this->alive($item->get(), $this->clock->now()->getTimestamp());
        unset($held[$tunnelId]);
        $this->cache->save($item->set($held)->expiresAfter(self::LIFETIME_SECONDS));
    }

    /**
     * @return array<string, int> identifiant de tunnel => échéance
     */
    private function alive(mixed $stored, int $now): array
    {
        $alive = [];
        if (\is_array($stored)) {
            foreach ($stored as $id => $expires) {
                if (\is_string($id) && \is_int($expires) && $expires > $now) {
                    $alive[$id] = $expires;
                }
            }
        }

        return $alive;
    }

    private function key(Uuid $user): string
    {
        return 'tunnels_'.str_replace('-', '', $user->toRfc4122());
    }
}
