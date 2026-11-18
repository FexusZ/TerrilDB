<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Crypto\KeyDerivation;
use App\Entity\Exception\InvalidScriptRunException;
use App\Entity\ScriptRun;
use App\Entity\ScriptRunStatus;
use App\Entity\User;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

final class ScriptRunTest extends TestCase
{
    private const HASH = '$argon2id$v=19$m=16384,t=3,p=1$c29tZXNhbHQ$aGFzaGhhc2hoYXNoaGFzaA';
    private const NOW = '2026-11-18T10:00:00+00:00';
    private const TEXT_HASH = 'a3f1c0de9b2e4d5f6a7b8c9d0e1f2a3b4c5d6e7f8091a2b3c4d5e6f708192a3b';

    private function at(int $seconds = 0): \DateTimeImmutable
    {
        return (new \DateTimeImmutable(self::NOW))->modify(\sprintf('%+d seconds', $seconds));
    }

    private function scriptRun(int $total = 3, bool $dryRun = false): ScriptRun
    {
        $user = new User('alice@example.test', 'alice', self::HASH, (new KeyDerivation(opsLimit: 1, memLimit: 8192))->newParameters(), $this->at());

        return new ScriptRun(Uuid::v7(), $user, Uuid::v7(), self::TEXT_HASH, $total, $dryRun, $this->at());
    }

    public function testANewRunIsQueuedAndKeepsWhatWasGiven(): void
    {
        $run = $this->scriptRun(3, true);

        self::assertSame(ScriptRunStatus::Queued, $run->getStatus());
        self::assertSame(3, $run->getTotal());
        self::assertSame(0, $run->getCompleted());
        self::assertTrue($run->isDryRun());
        self::assertFalse($run->isCancelRequested());
        self::assertSame([], $run->getStatements());
        self::assertNull($run->getFinishedAt());
        self::assertEquals($this->at(), $run->getHeartbeatAt());
    }

    public function testTheTotalIsBetweenOneAndFiveHundred(): void
    {
        foreach ([0, -1, 501] as $total) {
            try {
                $this->scriptRun($total);
                self::fail('A total of '.$total.' must be refused.');
            } catch (InvalidScriptRunException) {
                self::addToAssertionCount(1);
            }
        }
        self::assertSame(500, $this->scriptRun(500)->getTotal());
    }

    public function testTheTextHashMustBeAnSha256(): void
    {
        $this->expectException(InvalidScriptRunException::class);
        $user = new User('alice@example.test', 'alice', self::HASH, (new KeyDerivation(opsLimit: 1, memLimit: 8192))->newParameters(), $this->at());
        new ScriptRun(Uuid::v7(), $user, Uuid::v7(), 'SELECT 1; DROP TABLE t', 2, false, $this->at());
    }

    public function testStartingMovesToRunningAndBeatsTheHeart(): void
    {
        $run = $this->scriptRun();
        $run->start($this->at(5));

        self::assertSame(ScriptRunStatus::Running, $run->getStatus());
        self::assertEquals($this->at(5), $run->getHeartbeatAt());
        self::assertEquals($this->at(5), $run->getStartedAt());
    }

    public function testARunStartsOnlyOnce(): void
    {
        $run = $this->scriptRun();
        $run->start($this->at());

        $this->expectException(InvalidScriptRunException::class);
        $run->start($this->at(1));
    }

    public function testAHeartbeatMovesTheClockOfARunningRunOnly(): void
    {
        $run = $this->scriptRun();
        try {
            $run->beat($this->at(1));
            self::fail('A queued run has no heartbeat.');
        } catch (InvalidScriptRunException) {
            self::addToAssertionCount(1);
        }
        $run->start($this->at());
        $run->beat($this->at(10));

        self::assertEquals($this->at(10), $run->getHeartbeatAt());
    }

    public function testStatementsAreRecordedInOrderWithoutTheirText(): void
    {
        $run = $this->scriptRun();
        $run->start($this->at());
        $run->recordStatement(1, 'applied', 5, 12, null, $this->at(2));
        $run->recordStatement(2, 'failed', null, 3, '23505', $this->at(3));

        self::assertSame(2, $run->getCompleted());
        self::assertSame([
            ['rank' => 1, 'status' => 'applied', 'affectedRows' => 5, 'durationMs' => 12, 'sqlstate' => null],
            ['rank' => 2, 'status' => 'failed', 'affectedRows' => null, 'durationMs' => 3, 'sqlstate' => '23505'],
        ], $run->getStatements());
        self::assertEquals($this->at(3), $run->getHeartbeatAt());
    }

    public function testStatementsMustFollowTheRankAndStayWithinTheTotal(): void
    {
        $run = $this->scriptRun(2);
        $run->start($this->at());

        foreach ([[2, 'applied'], [0, 'applied'], [1, 'exploded']] as [$rank, $status]) {
            try {
                $run->recordStatement($rank, $status, null, 1, null, $this->at(1));
                self::fail('Refused: rank '.$rank.' status '.$status);
            } catch (InvalidScriptRunException) {
                self::addToAssertionCount(1);
            }
        }
        $run->recordStatement(1, 'applied', null, 1, null, $this->at(1));
        $run->recordStatement(2, 'applied', null, 1, null, $this->at(2));

        $this->expectException(InvalidScriptRunException::class);
        $run->recordStatement(3, 'applied', null, 1, null, $this->at(3));
    }

    public function testASqlstateIsFiveCharactersAndNeverATextOfTheEngine(): void
    {
        $run = $this->scriptRun();
        $run->start($this->at());

        $this->expectException(InvalidScriptRunException::class);
        $run->recordStatement(1, 'failed', null, 1, "Duplicate entry 'secret' for key", $this->at(1));
    }

    public function testFinishingIsTerminalAndRecordsTheEnd(): void
    {
        $run = $this->scriptRun();
        $run->start($this->at());
        $run->finish(ScriptRunStatus::Committed, $this->at(9));

        self::assertSame(ScriptRunStatus::Committed, $run->getStatus());
        self::assertTrue($run->getStatus()->isTerminal());
        self::assertEquals($this->at(9), $run->getFinishedAt());

        $this->expectException(InvalidScriptRunException::class);
        $run->finish(ScriptRunStatus::Failed, $this->at(10));
    }

    public function testOnlyAnEndingStatusCanFinishARun(): void
    {
        $run = $this->scriptRun();
        $run->start($this->at());

        foreach ([ScriptRunStatus::Queued, ScriptRunStatus::Running, ScriptRunStatus::Interrupted] as $status) {
            try {
                $run->finish($status, $this->at(1));
                self::fail($status->value.' is not a way to finish.');
            } catch (InvalidScriptRunException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function testACancelRequestIsKeptUntilTheRunEnds(): void
    {
        $run = $this->scriptRun();
        $run->start($this->at());
        $run->requestCancel();

        self::assertTrue($run->isCancelRequested());
        $run->finish(ScriptRunStatus::Cancelled, $this->at(4));

        $this->expectException(InvalidScriptRunException::class);
        $run->requestCancel();
    }

    public function testARunWithoutHeartbeatForThirtySecondsIsStale(): void
    {
        $run = $this->scriptRun();
        $run->start($this->at());

        self::assertFalse($run->isStale($this->at(30)));
        self::assertTrue($run->isStale($this->at(31)));
        $run->beat($this->at(31));
        self::assertFalse($run->isStale($this->at(60)));
    }

    public function testAQueuedRunAlsoGoesStaleSoANeverStartedRunDoesNotWaitForever(): void
    {
        self::assertTrue($this->scriptRun()->isStale($this->at(31)));
    }

    public function testAnEndedRunIsNeverStale(): void
    {
        $run = $this->scriptRun();
        $run->start($this->at());
        $run->finish(ScriptRunStatus::Committed, $this->at(1));

        self::assertFalse($run->isStale($this->at(10_000)));
    }

    public function testInterruptingKeepsWhatWasAppliedAndEndsTheRun(): void
    {
        $run = $this->scriptRun();
        $run->start($this->at());
        $run->recordStatement(1, 'applied', 1, 1, null, $this->at(1));
        $run->interrupt($this->at(60));

        self::assertSame(ScriptRunStatus::Interrupted, $run->getStatus());
        self::assertSame(1, $run->getCompleted());
        self::assertEquals($this->at(60), $run->getFinishedAt());
        self::assertTrue($run->getStatus()->isTerminal());

        $this->expectException(InvalidScriptRunException::class);
        $run->interrupt($this->at(61));
    }
}
