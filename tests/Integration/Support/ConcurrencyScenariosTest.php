<?php

declare(strict_types=1);

namespace App\Tests\Integration\Support;

use App\Tests\Integration\Driver\TargetDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Le jeu « file de tâches et stock » et, pour chaque famille de concurrence, UN scénario reproductible de bout en bout avec les aides
 * de session bloquante : mise à jour perdue et verrou optimiste, SKIP LOCKED / NOWAIT, interblocage en quatre étapes, DDL en attente
 * derrière un SELECT en transaction. Tout est jetable et supprimé même après un échec.
 */
#[Group('integration')]
final class ConcurrencyScenariosTest extends TestCase
{
    /** @return iterable<string, array{string}> */
    public static function targets(): iterable
    {
        return TargetDatabase::relational();
    }

    #[DataProvider('targets')]
    public function testTheDatasetHasItsShapeAndLeavesNothingBehind(string $env): void
    {
        $queue = WorkQueue::create($env);
        try {
            self::assertSame('1000', $queue->scalar(\sprintf("SELECT COUNT(*) FROM %s WHERE state = 'todo'", $queue->tasks)));
            self::assertSame('1000', $queue->scalar(\sprintf('SELECT COUNT(*) FROM %s', $queue->tasks)));
            self::assertSame('50', $queue->scalar(\sprintf('SELECT COUNT(*) FROM %s', $queue->stock)));
            self::assertSame('5000', $queue->scalar(\sprintf('SELECT SUM(qty) FROM %s', $queue->stock)));
            self::assertSame([$queue->tasks.'_state'], $queue->secondaryIndexes());
        } finally {
            $queue->drop();
        }

        self::assertSame([], SkewedTable::leftovers($env));
    }

    #[DataProvider('targets')]
    public function testALostUpdateEatsAnIncrementAndTheVersionColumnDetectsIt(string $env): void
    {
        $queue = WorkQueue::create($env);
        try {
            $read = (int) $queue->scalar(\sprintf('SELECT qty FROM %s WHERE id = 1', $queue->stock));

            // Un autre client retire une unité entre la lecture et l'écriture du premier.
            $queue->exec(\sprintf('UPDATE %s SET qty = qty - 1, version = version + 1 WHERE id = 1', $queue->stock));

            // Sans verrou optimiste : l'écriture part d'une valeur périmée et efface celle de l'autre.
            $queue->exec(\sprintf('UPDATE %s SET qty = %d WHERE id = 1', $queue->stock, $read - 1));
            self::assertSame('99', $queue->scalar(\sprintf('SELECT qty FROM %s WHERE id = 1', $queue->stock)), 'Two removals, one effect: the update was lost.');

            // Avec la colonne de version (lue avec la quantité) : l'écriture périmée ne touche aucune ligne, le conflit se voit.
            $version = (int) $queue->scalar(\sprintf('SELECT version FROM %s WHERE id = 2', $queue->stock));
            $queue->exec(\sprintf('UPDATE %s SET qty = qty - 1, version = version + 1 WHERE id = 2', $queue->stock));
            $touched = $queue->exec(\sprintf('UPDATE %s SET qty = %d, version = version + 1 WHERE id = 2 AND version = %d', $queue->stock, $read - 1, $version));
            self::assertSame(0, $touched, 'The version moved since the read: the write is refused.');
            self::assertSame('99', $queue->scalar(\sprintf('SELECT qty FROM %s WHERE id = 2', $queue->stock)), 'The other client\'s change is kept.');
        } finally {
            $queue->drop();
        }
    }

    #[DataProvider('targets')]
    public function testSkipLockedPassesTheLockedRowAndNowaitRefusesAtOnce(string $env): void
    {
        $queue = WorkQueue::create($env);
        try {
            BlockingSession::hold($env, [\sprintf('SELECT id FROM %s WHERE id = 1 FOR UPDATE', $queue->tasks)], static function () use ($queue): void {
                $db = $queue->connection();
                $db->beginTransaction();
                try {
                    $ids = $db->fetchFirstColumn(\sprintf("SELECT id FROM %s WHERE state = 'todo' ORDER BY id LIMIT 3 FOR UPDATE SKIP LOCKED", $queue->tasks));
                    self::assertSame([2, 3, 4], array_map(static fn (mixed $id): int => is_numeric($id) ? (int) $id : -1, $ids), 'The locked row is skipped, the next ones are taken.');

                    $started = microtime(true);
                    try {
                        $db->fetchAllAssociative(\sprintf('SELECT id FROM %s WHERE id = 1 FOR UPDATE NOWAIT', $queue->tasks));
                        self::fail('NOWAIT must refuse a locked row.');
                    } catch (\Doctrine\DBAL\Exception\DriverException) {
                        self::assertLessThan(2.0, microtime(true) - $started, 'NOWAIT does not wait.');
                    }
                } finally {
                    if ($db->isTransactionActive()) {
                        $db->rollBack();
                    }
                }
            });
        } finally {
            $queue->drop();
        }
    }

    #[DataProvider('targets')]
    public function testADeadlockInFourStepsHasExactlyOneVictim(string $env): void
    {
        $queue = WorkQueue::create($env);
        $waiter = null;
        try {
            $victims = BlockingSession::hold($env, [\sprintf('UPDATE %s SET version = version + 1 WHERE id = 2', $queue->tasks)], static function (BlockingSession $second) use ($env, $queue, &$waiter): int {
                // 1 et 2 : le second client tient la ligne 2. 3 : le premier prend la ligne 1 puis attend la ligne 2.
                $waiter = BlockedStatement::start($env, \sprintf('UPDATE %1$s SET version = version + 1 WHERE id = 1; UPDATE %1$s SET version = version + 1 WHERE id = 2', $queue->tasks), 15);
                usleep(700_000);
                self::assertTrue($waiter->isRunning(), 'The first client must be waiting for row 2.');

                // 4 : le second demande la ligne 1 : le cycle est fermé.
                $victims = 0;
                try {
                    $second->exec(\sprintf('UPDATE %s SET version = version + 1 WHERE id = 1', $queue->tasks));
                } catch (\PDOException $e) {
                    $victims += self::isDeadlock($e) ? 1 : 0;
                    $second->release();
                }
                $first = $waiter->result(20.0);
                $victims += !$first->succeeded && ('40P01' === $first->sqlState || '1213' === $first->driverCode) ? 1 : 0;

                return $victims;
            });
        } finally {
            $waiter?->stop();
            $queue->drop();
        }

        self::assertSame(1, $victims, 'The engine breaks the cycle by sacrificing exactly one of the two.');
    }

    #[DataProvider('targets')]
    public function testADdlWaitsBehindASelectInATransactionThenGoesThroughOnceItEnds(string $env): void
    {
        $queue = WorkQueue::create($env);
        $alter = \sprintf('ALTER TABLE %s ADD COLUMN note INT NULL', $queue->tasks);
        $waiter = null;
        try {
            $blocked = BlockingSession::hold($env, [\sprintf('SELECT COUNT(*) FROM %s', $queue->tasks)], static function () use ($env, $alter, &$waiter): BlockedResult {
                $waiter = BlockedStatement::start($env, $alter, 1);

                return $waiter->result();
            });
            $waiter?->stop();
            self::assertFalse($blocked->succeeded);
            self::assertTrue($blocked->lockTimeout, 'The DDL must give up on the lock, got '.$blocked->sqlState.'/'.$blocked->driverCode);
            self::assertNull($queue->column('note'), 'The refused ALTER changed nothing.');

            $free = BlockedStatement::start($env, $alter, 1);
            try {
                self::assertTrue($free->result()->succeeded, 'Without the open transaction the same ALTER goes through.');
            } finally {
                $free->stop();
            }
            self::assertNotNull($queue->column('note'));
        } finally {
            $queue->drop();
        }
    }

    private static function isDeadlock(\PDOException $e): bool
    {
        $info = \is_array($e->errorInfo) ? $e->errorInfo : [];

        return '40P01' === ($info[0] ?? '') || 1213 === ($info[1] ?? 0);
    }
}
