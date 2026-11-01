<?php

declare(strict_types=1);

namespace App\Http\Mcp;

use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Une session MCP appartient au jeton qui l'a ouverte. L'identifiant de session est un secret de transport, pas une
 * authentification : si un autre jeton le présente (même du même compte), la session n'existe pas pour lui.
 */
final readonly class McpSessionBinding
{
    private const LIFETIME_SECONDS = 86400;

    public function __construct(private CacheItemPoolInterface $cache)
    {
    }

    public function bind(string $sessionId, Uuid $tokenId): void
    {
        $item = $this->cache->getItem(self::key($sessionId));
        $item->set($tokenId->toRfc4122())->expiresAfter(self::LIFETIME_SECONDS);
        $this->cache->save($item);
    }

    public function belongsTo(string $sessionId, Uuid $tokenId): bool
    {
        $item = $this->cache->getItem(self::key($sessionId));

        return $item->isHit() && $item->get() === $tokenId->toRfc4122();
    }

    public function forget(string $sessionId): void
    {
        $this->cache->deleteItem(self::key($sessionId));
    }

    private static function key(string $sessionId): string
    {
        return 'mcp_session_'.hash('sha256', $sessionId);
    }
}
