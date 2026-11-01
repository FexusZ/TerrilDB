<?php

declare(strict_types=1);

namespace App\Tests\Integration\Driver;

use App\Core\KeyValue\Exception\KeyNotFoundException;
use App\Core\KeyValue\KeyType;
use App\Driver\Redis\RedisKeyValueStore;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Predis\Client;

#[Group('integration')]
final class RedisKeyValueStoreTest extends TestCase
{
    private Client $client;

    protected function setUp(): void
    {
        $this->client = RedisTarget::seeded();
    }

    protected function tearDown(): void
    {
        $this->client->flushdb();
    }

    private function store(): RedisKeyValueStore
    {
        return new RedisKeyValueStore($this->client);
    }

    /**
     * Parcourt toutes les pages et rend les noms, triés.
     *
     * @return list<string>
     */
    private function allKeys(string $pattern = '*', int $count = 2): array
    {
        $names = [];
        $cursor = null;
        do {
            $page = $this->store()->scan($pattern, $cursor, $count);
            foreach ($page->entries as $entry) {
                $names[] = $entry->name;
            }
            $cursor = $page->nextCursor;
        } while (null !== $cursor);
        sort($names);

        return $names;
    }

    public function testScansEveryKeyAcrossPages(): void
    {
        self::assertSame(
            ['events', 'profile:1', 'queue', 'scores', 'session:abc', 'tags', 'user:1', 'user:2', '日本語:キー'],
            $this->allKeys(),
        );
    }

    public function testScanFiltersByPattern(): void
    {
        self::assertSame(['user:1', 'user:2'], $this->allKeys('user:*'));
        self::assertSame([], $this->allKeys('nothing:*'));
    }

    public function testAnEmptyPatternMeansEverything(): void
    {
        self::assertCount(9, $this->allKeys(''));
    }

    public function testEachEntryCarriesItsTypeAndExpiry(): void
    {
        $byName = [];
        $cursor = null;
        do {
            $page = $this->store()->scan('*', $cursor, 100);
            foreach ($page->entries as $entry) {
                $byName[$entry->name] = $entry;
            }
            $cursor = $page->nextCursor;
        } while (null !== $cursor);

        self::assertSame(KeyType::String, $byName['user:1']->type);
        self::assertNull($byName['user:1']->ttlSeconds);
        self::assertSame(KeyType::Hash, $byName['profile:1']->type);
        self::assertSame(KeyType::List, $byName['queue']->type);
        self::assertSame(KeyType::Set, $byName['tags']->type);
        self::assertSame(KeyType::SortedSet, $byName['scores']->type);
        self::assertSame(KeyType::Stream, $byName['events']->type);
        self::assertGreaterThan(3500, $byName['session:abc']->ttlSeconds);
        self::assertLessThanOrEqual(3600, $byName['session:abc']->ttlSeconds);
    }

    public function testAPageIsSortedByNameAndNeverHugeWhateverTheAskedCount(): void
    {
        $page = $this->store()->scan('*', null, 1_000_000);

        $names = array_map(static fn ($e): string => $e->name, $page->entries);
        $sorted = $names;
        usort($sorted, strcmp(...));
        self::assertSame($sorted, $names);
    }

    public function testCountsTheKeysOfTheDatabase(): void
    {
        self::assertSame(9, $this->store()->size());
    }

    public function testInspectsAString(): void
    {
        $detail = $this->store()->inspect('user:1', 100);

        self::assertSame(KeyType::String, $detail->type);
        self::assertSame(['value'], $detail->columns);
        self::assertSame([['Alice']], $detail->rows);
        self::assertSame(5, $detail->size);
        self::assertFalse($detail->truncated);
    }

    public function testInspectsAHash(): void
    {
        $detail = $this->store()->inspect('profile:1', 100);

        self::assertSame(['field', 'value'], $detail->columns);
        $rows = $detail->rows;
        sort($rows);
        self::assertSame([['country', 'FR'], ['name', 'Alice']], $rows);
        self::assertSame(2, $detail->size);
    }

    public function testInspectsAListInOrder(): void
    {
        $detail = $this->store()->inspect('queue', 100);

        self::assertSame(['index', 'value'], $detail->columns);
        self::assertSame([[0, 'a'], [1, 'b'], [2, 'c']], $detail->rows);
    }

    public function testInspectsASet(): void
    {
        $detail = $this->store()->inspect('tags', 100);

        self::assertSame(['member'], $detail->columns);
        $rows = $detail->rows;
        sort($rows);
        self::assertSame([['green'], ['red']], $rows);
    }

    public function testInspectsASortedSetByScore(): void
    {
        $detail = $this->store()->inspect('scores', 100);

        self::assertSame(['member', 'score'], $detail->columns);
        self::assertSame([['bruno', 7.5], ['alice', 10.0]], $detail->rows);
    }

    public function testInspectsAStream(): void
    {
        $detail = $this->store()->inspect('events', 100);

        self::assertSame(['id', 'fields'], $detail->columns);
        self::assertSame([['1-1', '{"kind":"login","user":"1"}']], $detail->rows);
        self::assertSame(1, $detail->size);
    }

    public function testInspectionStopsAtTheRowLimitAndSaysSo(): void
    {
        $detail = $this->store()->inspect('queue', 2);

        self::assertCount(2, $detail->rows);
        self::assertSame(3, $detail->size);
        self::assertTrue($detail->truncated);
        self::assertFalse($this->store()->inspect('queue', 3)->truncated);
    }

    public function testALongStringIsCutAndFlagged(): void
    {
        $this->client->set('big', str_repeat('x', 200_000));

        $detail = $this->store()->inspect('big', 100);

        self::assertSame(200_000, $detail->size);
        self::assertTrue($detail->truncated);
        self::assertLessThanOrEqual(65_536, \strlen((string) $detail->rows[0][0]));
    }

    public function testBinaryValuesBecomeHex(): void
    {
        $this->client->set('bin', "\xff\xfe\x00");

        self::assertSame([['0xfffe00']], $this->store()->inspect('bin', 10)->rows);
    }

    public function testAHugeCollectionIsReadWithoutLoadingItAll(): void
    {
        $members = [];
        for ($i = 0; $i < 5000; ++$i) {
            $members[] = 'm'.$i;
        }
        $this->client->sadd('big-set', $members);

        $detail = $this->store()->inspect('big-set', 50);

        self::assertCount(50, $detail->rows);
        self::assertSame(5000, $detail->size);
        self::assertTrue($detail->truncated);
    }

    public function testAMissingKeyIsReported(): void
    {
        $this->expectException(KeyNotFoundException::class);

        $this->store()->inspect('does-not-exist', 10);
    }

    public function testAKeyNameIsNeverTakenAsACommand(): void
    {
        $this->expectException(KeyNotFoundException::class);

        $this->store()->inspect("user:1\r\nFLUSHDB", 10);
    }

    public function testKeysThatAreNotTextAreLeftOutOfTheListing(): void
    {
        $this->client->set("bin\xffkey", 'v');

        self::assertNotContains("bin\xffkey", $this->allKeys());
        self::assertCount(9, $this->allKeys());
    }
}
