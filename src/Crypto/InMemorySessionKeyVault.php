<?php

declare(strict_types=1);

namespace App\Crypto;

/**
 * Coffre en mémoire du processus : sert aux tests et à tout contexte à processus unique.
 */
final class InMemorySessionKeyVault implements SessionKeyVault
{
    /** @var array<string, DerivedKey> */
    private array $keys = [];

    public function put(string $sessionId, DerivedKey $key): void
    {
        $previous = $this->keys[$sessionId] ?? null;
        if (null !== $previous && $previous !== $key) {
            $previous->wipe();
        }

        $this->keys[$sessionId] = $key;
    }

    public function get(string $sessionId): ?DerivedKey
    {
        $key = $this->keys[$sessionId] ?? null;
        if (null !== $key && $key->isWiped()) {
            unset($this->keys[$sessionId]);

            return null;
        }

        return $key;
    }

    public function forget(string $sessionId): void
    {
        if (isset($this->keys[$sessionId])) {
            $this->keys[$sessionId]->wipe();
            unset($this->keys[$sessionId]);
        }
    }
}
