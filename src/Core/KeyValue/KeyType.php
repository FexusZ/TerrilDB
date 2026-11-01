<?php

declare(strict_types=1);

namespace App\Core\KeyValue;

/**
 * Le type d'une clé tel que Redis le rapporte (TYPE). Un type de module ou une clé disparue entre-temps devient `Other`.
 */
enum KeyType: string
{
    case String = 'string';
    case Hash = 'hash';
    case List = 'list';
    case Set = 'set';
    case SortedSet = 'zset';
    case Stream = 'stream';
    case Other = 'other';

    public static function fromRedis(string $name): self
    {
        return self::tryFrom($name) ?? self::Other;
    }
}
