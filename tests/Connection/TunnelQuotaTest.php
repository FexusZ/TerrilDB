<?php

declare(strict_types=1);

namespace App\Tests\Connection;

use App\Connection\TunnelQuota;
use App\Core\Connection\Tunnel\TooManyTunnelsException;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Uid\Uuid;

final class TunnelQuotaTest extends TestCase
{
    private MockClock $clock;

    private function quota(?ArrayAdapter $cache = null): TunnelQuota
    {
        $this->clock = new MockClock('2026-11-14 10:00:00 UTC');

        return new TunnelQuota($cache ?? new ArrayAdapter(), $this->clock);
    }

    private static function id(int $n): string
    {
        return \sprintf('%032x', $n);
    }

    public function testAnAccountCannotHoldMoreThanItsQuota(): void
    {
        $quota = $this->quota();
        $user = Uuid::v7();
        for ($n = 1; $n <= TunnelQuota::MAX_PER_ACCOUNT; ++$n) {
            $quota->admit($user, self::id($n));
        }

        $this->expectException(TooManyTunnelsException::class);

        $quota->admit($user, self::id(99));
    }

    public function testATunnelAlreadyCountedDoesNotCountTwice(): void
    {
        $quota = $this->quota();
        $user = Uuid::v7();
        for ($n = 1; $n <= TunnelQuota::MAX_PER_ACCOUNT; ++$n) {
            $quota->admit($user, self::id($n));
        }

        $quota->admit($user, self::id(1));

        $this->addToAssertionCount(1);
    }

    public function testAnotherAccountKeepsItsCapacity(): void
    {
        $quota = $this->quota();
        $greedy = Uuid::v7();
        for ($n = 1; $n <= TunnelQuota::MAX_PER_ACCOUNT; ++$n) {
            $quota->admit($greedy, self::id($n));
        }

        $quota->admit(Uuid::v7(), self::id(50));

        $this->addToAssertionCount(1);
    }

    public function testAReleasedTunnelFreesASlot(): void
    {
        $quota = $this->quota();
        $user = Uuid::v7();
        for ($n = 1; $n <= TunnelQuota::MAX_PER_ACCOUNT; ++$n) {
            $quota->admit($user, self::id($n));
        }

        $quota->release($user, self::id(3));
        $quota->admit($user, self::id(99));

        $this->addToAssertionCount(1);
    }

    public function testTunnelsExpireWithTheIdlePeriodOfTheRelay(): void
    {
        $quota = $this->quota();
        $user = Uuid::v7();
        for ($n = 1; $n <= TunnelQuota::MAX_PER_ACCOUNT; ++$n) {
            $quota->admit($user, self::id($n));
        }

        $this->clock->sleep(301);
        $quota->admit($user, self::id(99));

        $this->addToAssertionCount(1);
    }

    public function testUsingATunnelAgainRenewsItsExpiry(): void
    {
        $quota = $this->quota();
        $user = Uuid::v7();
        $quota->admit($user, self::id(1));
        $this->clock->sleep(200);
        $quota->admit($user, self::id(1));
        $this->clock->sleep(200);
        for ($n = 2; $n <= TunnelQuota::MAX_PER_ACCOUNT; ++$n) {
            $quota->admit($user, self::id($n));
        }

        $this->expectException(TooManyTunnelsException::class);

        $quota->admit($user, self::id(99));
    }
}
