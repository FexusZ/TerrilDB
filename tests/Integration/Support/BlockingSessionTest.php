<?php

declare(strict_types=1);

namespace App\Tests\Integration\Support;

use App\Core\Connection\Engine;
use App\Tests\Integration\Driver\LockPeer;
use App\Tests\Integration\Driver\TargetDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Preuve des aides de session bloquante : la seconde session tient vraiment le verrou, la libération le rend, et rien ne reste
 * (ni session, ni sous-processus, ni table).
 */
#[Group('integration')]
final class BlockingSessionTest extends TestCase
{
    private string $env = '';
    private string $table = '';

    /** @return iterable<string, array{string}> */
    public static function engines(): iterable
    {
        yield from TargetDatabase::relational();
    }

    protected function tearDown(): void
    {
        if ('' !== $this->table) {
            LockPeer::connect($this->env)->exec(\sprintf('DROP TABLE IF EXISTS %s', $this->table));
        }
    }

    #[DataProvider('engines')]
    public function testTheSecondSessionReallyHoldsTheLockUntilReleased(string $env): void
    {
        $this->seed($env);
        $session = BlockingSession::open($env);
        $id = $session->id();

        try {
            $session->begin()->exec(\sprintf('SELECT * FROM %s WHERE id = 1 FOR UPDATE', $this->table));

            $waiter = BlockedStatement::start($env, \sprintf('UPDATE %s SET v = v + 1 WHERE id = 1', $this->table), 1);
            try {
                $result = $waiter->result();
            } finally {
                $waiter->stop();
            }
        } finally {
            $session->release();
        }

        self::assertFalse($result->succeeded, 'The statement must wait for the lock then give up.');
        self::assertGreaterThanOrEqual(0.8, $result->seconds);
        self::assertLessThan(10.0, $result->seconds);
        self::assertTrue($result->lockTimeout, 'Expected a lock timeout, got '.$result->sqlState.'/'.$result->driverCode);
        self::assertTrue(BlockingSession::waitUntilGone($env, $id), 'The blocking session must be gone.');
    }

    #[DataProvider('engines')]
    public function testReleasingTheLockLetsTheWaitingStatementThrough(string $env): void
    {
        $this->seed($env);

        $result = BlockingSession::hold($env, [\sprintf('SELECT * FROM %s WHERE id = 1 FOR UPDATE', $this->table)], function (BlockingSession $held) use ($env): BlockedResult {
            $waiter = BlockedStatement::start($env, \sprintf('UPDATE %s SET v = v + 1 WHERE id = 1', $this->table), 10);
            try {
                usleep(400_000);
                self::assertTrue($waiter->isRunning(), 'The statement must be waiting, not finished.');
                $held->release();

                return $waiter->result();
            } finally {
                $waiter->stop();
            }
        });

        self::assertTrue($result->succeeded, 'Released lock: must complete (t='.$result->seconds.' '.$result->sqlState.'/'.$result->driverCode.')');
        self::assertSame(1, (int) BlockingSession::scalar(LockPeer::connect($env), \sprintf('SELECT v FROM %s WHERE id = 1', $this->table)));
    }

    #[DataProvider('engines')]
    public function testHoldReleasesEvenWhenTheCallbackThrows(string $env): void
    {
        $this->seed($env);
        $seen = 0;
        $caught = '';

        try {
            BlockingSession::hold($env, [\sprintf('SELECT * FROM %s WHERE id = 1 FOR UPDATE', $this->table)], static function (BlockingSession $held) use (&$seen): void {
                $seen = $held->id();
                throw new \RuntimeException('boom');
            });
        } catch (\RuntimeException $e) {
            $caught = $e->getMessage();
        }

        self::assertSame('boom', $caught, 'The callback exception must propagate.');

        self::assertGreaterThan(0, $seen);
        self::assertTrue(BlockingSession::waitUntilGone($env, $seen));
        // Le verrou est rendu : une écriture immédiate passe.
        LockPeer::connect($env)->exec(\sprintf('UPDATE %s SET v = 5 WHERE id = 1', $this->table));
    }

    #[DataProvider('engines')]
    public function testStopKillsAStillWaitingProcess(string $env): void
    {
        $this->seed($env);
        $waiter = null;

        BlockingSession::hold($env, [\sprintf('SELECT * FROM %s WHERE id = 1 FOR UPDATE', $this->table)], function () use ($env, &$waiter): void {
            $waiter = BlockedStatement::start($env, \sprintf('UPDATE %s SET v = 9 WHERE id = 1', $this->table), 30);
            self::assertTrue($waiter->isRunning());
            $waiter->stop();
        });

        self::assertInstanceOf(BlockedStatement::class, $waiter);
        self::assertFalse($waiter->isRunning());
        self::assertTrue(BlockingSession::waitUntilGone($env, $waiter->backendId()), 'The waiting backend must be gone once the process is killed.');
    }

    private function seed(string $env): void
    {
        $this->env = $env;
        $this->table = 'tdb_h_'.bin2hex(random_bytes(4));
        $pdo = LockPeer::connect($env);
        $pdo->exec(\sprintf('CREATE TABLE %s (id INT PRIMARY KEY, v INT NOT NULL)', $this->table));
        $pdo->exec(\sprintf('INSERT INTO %s VALUES (1, 0), (2, 0)', $this->table));
        self::assertNotSame(Engine::ClickHouse, TargetDatabase::engine($env));
    }
}
