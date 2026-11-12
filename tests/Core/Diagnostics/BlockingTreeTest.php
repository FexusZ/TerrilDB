<?php

declare(strict_types=1);

namespace App\Tests\Core\Diagnostics;

use App\Core\Diagnostics\BlockingNode;
use App\Core\Diagnostics\BlockingTree;
use App\Core\Diagnostics\LockKind;
use App\Core\Diagnostics\LockParty;
use App\Core\Diagnostics\LockWait;
use App\Core\Guard\SqlDialect;
use PHPUnit\Framework\TestCase;

final class BlockingTreeTest extends TestCase
{
    private static function wait(string $waiting, string $blocking, int $seconds = 5): LockWait
    {
        return new LockWait(new LockParty($waiting, 'u'.$waiting, 'UPDATE t'.$waiting, SqlDialect::MySql), new LockParty($blocking, 'u'.$blocking, null, SqlDialect::MySql), $seconds, LockKind::Row, 'X', 'shop.orders');
    }

    /**
     * @param list<BlockingNode> $nodes
     *
     * @return list<string>
     */
    private static function ids(array $nodes): array
    {
        return array_map(static fn (BlockingNode $n): string => $n->party->id, $nodes);
    }

    public function testNoWaitIsNoTree(): void
    {
        self::assertSame([], BlockingTree::build([]));
    }

    public function testTheRootIsWhoBlocksWithoutWaiting(): void
    {
        $tree = BlockingTree::build([self::wait('2', '1')]);

        self::assertSame(['1'], self::ids($tree));
        self::assertNull($tree[0]->seconds, 'The root does not wait.');
        self::assertSame(['2'], self::ids($tree[0]->blocked));
        self::assertSame(5, $tree[0]->blocked[0]->seconds);
        self::assertSame('X', $tree[0]->blocked[0]->mode);
        self::assertSame('shop.orders', $tree[0]->blocked[0]->object);
        self::assertSame(LockKind::Row, $tree[0]->blocked[0]->kind);
        self::assertSame('UPDATE t2', $tree[0]->blocked[0]->party->query);
    }

    public function testAChainIsNested(): void
    {
        $tree = BlockingTree::build([self::wait('3', '2'), self::wait('2', '1')]);

        self::assertSame(['1'], self::ids($tree));
        self::assertSame(['2'], self::ids($tree[0]->blocked));
        self::assertSame(['3'], self::ids($tree[0]->blocked[0]->blocked));
    }

    public function testSeveralWaitersOfOneBlockerAreSortedByWaitingTime(): void
    {
        $tree = BlockingTree::build([self::wait('2', '1', 3), self::wait('3', '1', 30)]);

        self::assertSame(['3', '2'], self::ids($tree[0]->blocked));
    }

    public function testRootsAreSortedByTheNumberOfSessionsTheyHold(): void
    {
        $tree = BlockingTree::build([self::wait('2', '1'), self::wait('4', '3'), self::wait('5', '3')]);

        self::assertSame(['3', '1'], self::ids($tree));
    }

    public function testACycleTerminatesAndShowsEveryone(): void
    {
        $tree = BlockingTree::build([self::wait('1', '2'), self::wait('2', '1')]);

        self::assertNotEmpty($tree);
        $seen = array_fill_keys(self::all($tree), true);
        ksort($seen);
        self::assertSame(['1' => true, '2' => true], $seen);
    }

    /**
     * @param list<BlockingNode> $nodes
     *
     * @return list<string>
     */
    private static function all(array $nodes): array
    {
        $ids = [];
        foreach ($nodes as $node) {
            $ids = [...$ids, $node->party->id, ...self::all($node->blocked)];
        }

        return $ids;
    }
}
