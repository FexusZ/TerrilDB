<?php

declare(strict_types=1);

namespace App\Driver\Redis;

use App\Core\KeyValue\Exception\KeyNotFoundException;
use App\Core\KeyValue\KeyDetail;
use App\Core\KeyValue\KeyEntry;
use App\Core\KeyValue\KeyPage;
use App\Core\KeyValue\KeyType;
use App\Core\KeyValue\KeyValueStore;
use App\Core\Query\CellValue;
use Predis\Client;
use Predis\Pipeline\Pipeline;

/**
 * Navigation dans une base Redis. Toujours SCAN (jamais KEYS, qui bloque le serveur) et des lectures bornées : une
 * collection énorme est lue par tranches (HSCAN, SSCAN) ou par intervalle (LRANGE, ZRANGE, XRANGE COUNT), une chaîne
 * géante par GETRANGE. Les noms de clés sont des arguments, jamais du texte de commande. Une clé dont le nom n'est pas
 * du texte UTF-8 est laissée de côté : elle ne pourrait pas voyager en JSON ni être rouverte par son nom.
 */
final readonly class RedisKeyValueStore implements KeyValueStore
{
    private const MAX_SCAN_COUNT = 1000;
    private const MAX_STRING_BYTES = 65_536;
    private const MAX_INSPECT_ROWS = 10_000;

    public function __construct(private Client $client)
    {
    }

    public function scan(string $pattern, ?string $cursor, int $count): KeyPage
    {
        $reply = $this->call(['SCAN', $cursor ?? '0', 'MATCH', '' === $pattern ? '*' : $pattern, 'COUNT', (string) max(1, min($count, self::MAX_SCAN_COUNT))]);
        $next = \is_array($reply) && \is_scalar($reply[0] ?? null) ? (string) $reply[0] : '0';
        $names = [];
        foreach (\is_array($reply) && \is_array($reply[1] ?? null) ? $reply[1] : [] as $name) {
            if (\is_string($name) && '' !== $name && mb_check_encoding($name, 'UTF-8')) {
                $names[] = $name;
            }
        }

        $entries = [];
        if ([] !== $names) {
            $replies = $this->client->pipeline(static function (Pipeline $pipeline) use ($names): void {
                foreach ($names as $name) {
                    $pipeline->type($name);
                    $pipeline->ttl($name);
                }
            });
            $replies = \is_array($replies) ? array_values($replies) : [];
            foreach ($names as $index => $name) {
                $ttl = $replies[$index * 2 + 1] ?? -2;
                if (-2 === $ttl) {
                    continue; // expirée entre le SCAN et la lecture
                }
                $type = $replies[$index * 2] ?? 'none';
                $entries[] = new KeyEntry($name, KeyType::fromRedis(\is_scalar($type) || $type instanceof \Stringable ? (string) $type : 'none'), \is_int($ttl) && $ttl >= 0 ? $ttl : null);
            }
        }
        usort($entries, static fn (KeyEntry $a, KeyEntry $b): int => strcmp($a->name, $b->name));

        return new KeyPage($entries, '0' === $next ? null : $next);
    }

    public function size(): int
    {
        $size = $this->call(['DBSIZE']);

        return \is_int($size) ? $size : 0;
    }

    public function inspect(string $key, int $limit): KeyDetail
    {
        $limit = max(1, min($limit, self::MAX_INSPECT_ROWS));
        $typeReply = $this->call(['TYPE', $key]);
        $type = KeyType::fromRedis(\is_string($typeReply) ? $typeReply : 'none');
        $ttlReply = $this->call(['TTL', $key]);
        if (-2 === $ttlReply) {
            throw KeyNotFoundException::named($key);
        }
        $ttl = \is_int($ttlReply) && $ttlReply >= 0 ? $ttlReply : null;

        return match ($type) {
            KeyType::String => $this->string($key, $ttl),
            KeyType::Hash => $this->hash($key, $ttl, $limit),
            KeyType::List => $this->list($key, $ttl, $limit),
            KeyType::Set => $this->set($key, $ttl, $limit),
            KeyType::SortedSet => $this->sortedSet($key, $ttl, $limit),
            KeyType::Stream => $this->stream($key, $ttl, $limit),
            KeyType::Other => new KeyDetail($key, $type, $ttl, null, ['value'], [], false),
        };
    }

    private function string(string $key, ?int $ttl): KeyDetail
    {
        $length = $this->int($this->call(['STRLEN', $key]));
        $value = $this->call(['GETRANGE', $key, '0', (string) (self::MAX_STRING_BYTES - 1)]);

        return new KeyDetail($key, KeyType::String, $ttl, $length, ['value'], [[CellValue::normalize(\is_string($value) ? $value : '')]], $length > self::MAX_STRING_BYTES);
    }

    private function hash(string $key, ?int $ttl, int $limit): KeyDetail
    {
        $size = $this->int($this->call(['HLEN', $key]));
        $rows = [];
        foreach ($this->scanCollection('HSCAN', $key, $limit, 2) as $pair) {
            $rows[] = [CellValue::normalize($pair[0]), CellValue::normalize($pair[1] ?? null)];
        }

        return new KeyDetail($key, KeyType::Hash, $ttl, $size, ['field', 'value'], $rows, $size > \count($rows));
    }

    private function list(string $key, ?int $ttl, int $limit): KeyDetail
    {
        $size = $this->int($this->call(['LLEN', $key]));
        $items = $this->call(['LRANGE', $key, '0', (string) ($limit - 1)]);
        $rows = [];
        foreach (\is_array($items) ? array_values($items) : [] as $index => $item) {
            $rows[] = [$index, CellValue::normalize($item)];
        }

        return new KeyDetail($key, KeyType::List, $ttl, $size, ['index', 'value'], $rows, $size > \count($rows));
    }

    private function set(string $key, ?int $ttl, int $limit): KeyDetail
    {
        $size = $this->int($this->call(['SCARD', $key]));
        $rows = [];
        foreach ($this->scanCollection('SSCAN', $key, $limit, 1) as $member) {
            $rows[] = [CellValue::normalize($member[0])];
        }

        return new KeyDetail($key, KeyType::Set, $ttl, $size, ['member'], $rows, $size > \count($rows));
    }

    private function sortedSet(string $key, ?int $ttl, int $limit): KeyDetail
    {
        $size = $this->int($this->call(['ZCARD', $key]));
        $reply = $this->call(['ZRANGE', $key, '0', (string) ($limit - 1), 'WITHSCORES']);
        $rows = [];
        foreach (array_chunk(\is_array($reply) ? array_values($reply) : [], 2) as $pair) {
            $rows[] = [CellValue::normalize($pair[0]), is_numeric($pair[1] ?? null) ? (float) $pair[1] : null];
        }

        return new KeyDetail($key, KeyType::SortedSet, $ttl, $size, ['member', 'score'], $rows, $size > \count($rows));
    }

    private function stream(string $key, ?int $ttl, int $limit): KeyDetail
    {
        $size = $this->int($this->call(['XLEN', $key]));
        $reply = $this->call(['XRANGE', $key, '-', '+', 'COUNT', (string) $limit]);
        $rows = [];
        foreach (\is_array($reply) ? $reply : [] as $entry) {
            if (!\is_array($entry) || !\is_string($entry[0] ?? null)) {
                continue;
            }
            $fields = [];
            foreach (array_chunk(\is_array($entry[1] ?? null) ? array_values($entry[1]) : [], 2) as $pair) {
                $fields[\is_string($pair[0]) ? $pair[0] : ''] = CellValue::normalize($pair[1] ?? null);
            }
            $rows[] = [$entry[0], json_encode($fields, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES | \JSON_PARTIAL_OUTPUT_ON_ERROR) ?: '{}'];
        }

        return new KeyDetail($key, KeyType::Stream, $ttl, $size, ['id', 'fields'], $rows, $size > \count($rows));
    }

    /**
     * Lit une collection par tranches de SCAN jusqu'à `$limit` éléments (chaque élément a `$width` valeurs).
     *
     * @return list<list<mixed>>
     */
    private function scanCollection(string $command, string $key, int $limit, int $width): array
    {
        $items = [];
        $cursor = '0';
        do {
            $reply = $this->call([$command, $key, $cursor, 'COUNT', (string) min($limit, self::MAX_SCAN_COUNT)]);
            $cursor = \is_array($reply) && \is_scalar($reply[0] ?? null) ? (string) $reply[0] : '0';
            $flat = \is_array($reply) && \is_array($reply[1] ?? null) ? array_values($reply[1]) : [];
            foreach (array_chunk($flat, max(1, $width)) as $item) {
                if (\count($items) >= $limit) {
                    return $items;
                }
                $items[] = $item;
            }
        } while ('0' !== $cursor);

        return $items;
    }

    private function int(mixed $reply): int
    {
        return \is_int($reply) ? $reply : 0;
    }

    /**
     * @param list<string> $arguments
     *
     * @throws RedisReplyException
     */
    private function call(array $arguments): mixed
    {
        $error = false;
        $reply = $this->client->executeRaw($arguments, $error);
        if ($error) {
            throw RedisReplyException::fromReply(\is_string($reply) ? $reply : 'ERR unexpected reply');
        }

        return $reply;
    }
}
