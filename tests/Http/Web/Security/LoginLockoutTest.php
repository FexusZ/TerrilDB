<?php

declare(strict_types=1);

namespace App\Tests\Http\Web\Security;

use App\Http\Json\TooManyAttemptsException;
use App\Http\Web\Security\LockoutKind;
use App\Http\Web\Security\LoginLockout;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Clock\MockClock;

final class LoginLockoutTest extends TestCase
{
    private const IP = '203.0.113.7';

    private MockClock $clock;
    private LoginLockout $lockout;

    protected function setUp(): void
    {
        $this->clock = new MockClock('2026-01-01 10:00:00 UTC');
        $this->lockout = new LoginLockout(new ArrayAdapter(), $this->clock);
    }

    private function failTimes(string $login, int $times, string $ip = self::IP): void
    {
        for ($i = 0; $i < $times; ++$i) {
            $this->lockout->check($ip, $login);
            $this->lockout->failed($ip, $login);
        }
    }

    private function retryAfter(string $login, string $ip = self::IP): ?int
    {
        try {
            $this->lockout->check($ip, $login);
        } catch (TooManyAttemptsException $e) {
            return $e->retryAfter;
        }

        return null;
    }

    public function testFourFailuresStillLetTheFifthAttemptThrough(): void
    {
        $this->failTimes('alice', 4);

        self::assertNull($this->retryAfter('alice'));
    }

    public function testTheFifthConsecutiveFailureBlocksThePairForFifteenMinutes(): void
    {
        $this->failTimes('alice', 4);
        $outcome = $this->lockout->failed(self::IP, 'alice');

        self::assertNotNull($outcome);
        self::assertSame(LockoutKind::PairBlocked, $outcome->kind);
        self::assertSame(900, $outcome->seconds);
        self::assertSame(900, $this->retryAfter('alice'));

        $this->clock->sleep(899);
        self::assertSame(1, $this->retryAfter('alice'));
        $this->clock->sleep(1);
        self::assertNull($this->retryAfter('alice'));
    }

    public function testEachNewBlockOfThePairLastsLonger(): void
    {
        $seen = [];
        foreach ([900, 3600, 10800, 21600, 43200, 43200] as $_) {
            $this->failTimes('alice', 5);
            $seen[] = $this->retryAfter('alice');
            $this->clock->sleep((int) end($seen));
        }

        self::assertSame([900, 3600, 10800, 21600, 43200, 43200], $seen);
    }

    public function testASuccessForgetsTheFailuresInARow(): void
    {
        $this->failTimes('alice', 4);
        $this->lockout->succeeded(self::IP, 'alice');
        $this->failTimes('alice', 4);

        self::assertNull($this->retryAfter('alice'));
    }

    public function testAQuietDayAfterABlockForgetsItsLevel(): void
    {
        $this->failTimes('alice', 5);
        $this->clock->sleep(900);
        $this->failTimes('alice', 5);
        self::assertSame(3600, $this->retryAfter('alice'));

        $this->clock->sleep(3600 + 86400 + 1);
        $this->failTimes('alice', 5);

        self::assertSame(900, $this->retryAfter('alice'));
    }

    public function testABlockedPairLeavesOtherAccountsAndOtherAddressesAlone(): void
    {
        $this->failTimes('alice', 5);

        self::assertNull($this->retryAfter('bob'));
        self::assertNull($this->retryAfter('alice', '198.51.100.9'));
    }

    public function testTheLoginIsComparedWithoutCaseNorSurroundingSpaces(): void
    {
        $this->failTimes('Alice', 3);
        $this->failTimes('  alice ', 2);

        self::assertSame(900, $this->retryAfter('ALICE'));
    }

    public function testAnAddressBlockedOnThreeDifferentAccountsIsBannedForADay(): void
    {
        $this->failTimes('alice', 5);
        $this->failTimes('bob', 5);
        $outcome = null;
        for ($i = 0; $i < 5; ++$i) {
            $outcome = $this->lockout->failed(self::IP, 'carol');
        }

        self::assertNotNull($outcome);
        self::assertSame(LockoutKind::AddressBanned, $outcome->kind);
        self::assertSame(86400, $outcome->seconds);
        self::assertSame(86400, $this->retryAfter('dave'));
        self::assertNull($this->retryAfter('dave', '198.51.100.9'));
    }

    public function testBlockingTheSameAccountAgainDoesNotCountAsAnotherAccount(): void
    {
        foreach ([900, 3600, 10800] as $wait) {
            $this->failTimes('alice', 5);
            $this->clock->sleep($wait);
        }

        self::assertNull($this->retryAfter('dave'));
    }

    public function testABanLiftsThenTheNextOneIsLonger(): void
    {
        foreach (['alice', 'bob', 'carol'] as $login) {
            $this->failTimes($login, 5);
        }
        $this->clock->sleep(86400);
        self::assertNull($this->retryAfter('dave'));

        foreach (['erin', 'frank', 'grace'] as $login) {
            $this->failTimes($login, 5);
        }

        self::assertSame(259200, $this->retryAfter('dave'));
    }

    public function testAnUnknownAddressIsTreatedAsOneAddress(): void
    {
        $this->failTimes('alice', 5, ip: '');
        for ($i = 0; $i < 5; ++$i) {
            $this->lockout->failed(null, 'alice');
        }

        self::assertNotNull($this->retryAfter('alice', ip: ''));
    }
}
