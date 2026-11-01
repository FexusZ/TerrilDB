<?php

declare(strict_types=1);

namespace App\Core\KeyValue;

use App\Core\KeyValue\Exception\InvalidKeyException;

/**
 * Une ligne de la liste des clés : le nom, le type et le temps restant avant expiration (null = pas d'expiration).
 */
final readonly class KeyEntry
{
    public function __construct(
        public string $name,
        public KeyType $type,
        public ?int $ttlSeconds,
    ) {
        if ('' === $name) {
            throw new InvalidKeyException('A key needs a name.');
        }
        if (null !== $ttlSeconds && $ttlSeconds < 0) {
            throw new InvalidKeyException('The time to live cannot be negative.');
        }
    }
}
