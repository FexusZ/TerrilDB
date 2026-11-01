<?php

declare(strict_types=1);

namespace App\Tests\Core\KeyValue;

use App\Core\KeyValue\Exception\InvalidKeyException;
use App\Core\KeyValue\KeyDetail;
use App\Core\KeyValue\KeyEntry;
use App\Core\KeyValue\KeyPage;
use App\Core\KeyValue\KeyType;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class KeyModelTest extends TestCase
{
    /**
     * @return iterable<string, array{string, KeyType}>
     */
    public static function types(): iterable
    {
        yield 'string' => ['string', KeyType::String];
        yield 'hash' => ['hash', KeyType::Hash];
        yield 'list' => ['list', KeyType::List];
        yield 'set' => ['set', KeyType::Set];
        yield 'sorted set' => ['zset', KeyType::SortedSet];
        yield 'stream' => ['stream', KeyType::Stream];
        yield 'module type' => ['ReJSON-RL', KeyType::Other];
        yield 'nothing' => ['none', KeyType::Other];
    }

    #[DataProvider('types')]
    public function testReadsTheTypeNameTheServerGives(string $name, KeyType $expected): void
    {
        self::assertSame($expected, KeyType::fromRedis($name));
    }

    public function testAnEntryKeepsItsNameTypeAndExpiry(): void
    {
        $entry = new KeyEntry('session:42', KeyType::Hash, 120);

        self::assertSame('session:42', $entry->name);
        self::assertSame(KeyType::Hash, $entry->type);
        self::assertSame(120, $entry->ttlSeconds);
        self::assertNull((new KeyEntry('a', KeyType::String, null))->ttlSeconds);
    }

    public function testAKeyNeedsANameAndNoNegativeExpiry(): void
    {
        $this->expectException(InvalidKeyException::class);

        new KeyEntry('', KeyType::String, null);
    }

    public function testAnExpiryCannotBeNegative(): void
    {
        $this->expectException(InvalidKeyException::class);

        new KeyEntry('a', KeyType::String, -5);
    }

    public function testAPageKnowsWhetherThereIsMore(): void
    {
        $entry = new KeyEntry('a', KeyType::String, null);

        self::assertTrue((new KeyPage([$entry], '17'))->hasMore());
        self::assertFalse((new KeyPage([$entry], null))->hasMore());
    }

    public function testADetailIsATableOfValues(): void
    {
        $detail = new KeyDetail('prices', KeyType::SortedSet, null, 2, ['member', 'score'], [['a', 1.5], ['b', 2]], false);

        self::assertSame(['member', 'score'], $detail->columns);
        self::assertSame(2, $detail->size);
        self::assertFalse($detail->truncated);
    }

    public function testADetailRowMatchesItsColumns(): void
    {
        $this->expectException(InvalidKeyException::class);

        new KeyDetail('prices', KeyType::Hash, null, 1, ['field', 'value'], [['only-one']], false);
    }
}
