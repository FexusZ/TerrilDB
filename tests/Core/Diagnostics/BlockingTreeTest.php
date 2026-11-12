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

    public function testManyWaitersOfOneLockStayLinear(): void
    {
        // Chaque attendant est bloqué par le détenteur ET par tous ceux qui sont devant lui (ce que rendent les moteurs).
        $waits = [];
        for ($i = 1; $i <= 45; ++$i) {
            for ($j = 0; $j < $i; ++$j) {
                $waits[] = self::wait((string) $i, (string) $j, 100 - $i);
            }
        }

        $forest = BlockingTree::forest($waits);

        $ids = self::all($forest->roots);
        self::assertCount(46, $ids, 'Each session is shown once.');
        self::assertCount(46, array_unique($ids));
        self::assertFalse($forest->truncated);
        self::assertSame(['0'], self::ids($forest->roots), 'The holder is the root.');
    }

    public function testASessionWaitingForSeveralIsShownOnceUnderTheHolder(): void
    {
        $tree = BlockingTree::build([self::wait('3', '1'), self::wait('3', '2'), self::wait('2', '1')]);

        self::assertSame(['1'], self::ids($tree));
        self::assertSame(['1', '2', '3'], (static function (array $ids): array {
            sort($ids);

            return $ids;
        })(self::all($tree)));
    }

    public function testTheNumberOfNodesIsBounded(): void
    {
        $waits = [];
        for ($i = 1; $i <= 2000; ++$i) {
            $waits[] = self::wait((string) $i, '0');
        }

        $forest = BlockingTree::forest($waits);

        self::assertCount(BlockingTree::MAX_NODES, self::all($forest->roots));
        self::assertTrue($forest->truncated);
    }

    public function testTheDepthIsBounded(): void
    {
        $waits = [];
        for ($i = 1; $i <= 60; ++$i) {
            $waits[] = self::wait((string) $i, (string) ($i - 1));
        }

        $forest = BlockingTree::forest($waits);

        self::assertCount(BlockingTree::MAX_DEPTH, self::all($forest->roots));
        self::assertTrue($forest->truncated);
    }

    public function testALongCycleTerminates(): void
    {
        $waits = [];
        for ($i = 0; $i < 15; ++$i) {
            $waits[] = self::wait((string) $i, (string) (($i + 1) % 15));
        }

        $forest = BlockingTree::forest($waits);

        self::assertCount(15, array_unique(self::all($forest->roots)));
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
