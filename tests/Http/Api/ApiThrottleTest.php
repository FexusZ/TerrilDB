<?php

declare(strict_types=1);

namespace App\Tests\Http\Api;

use App\Http\Api\ApiThrottle;
use App\Http\Json\TooManyAttemptsException;
use PHPUnit\Framework\TestCase;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;
use Symfony\Component\Uid\Uuid;

final class ApiThrottleTest extends TestCase
{
    private function throttle(int $failures = 3, int $uses = 5): ApiThrottle
    {
        $storage = new InMemoryStorage();

        return new ApiThrottle(
            new RateLimiterFactory(['id' => 'failures', 'policy' => 'sliding_window', 'limit' => $failures, 'interval' => '15 minutes'], $storage),
            new RateLimiterFactory(['id' => 'uses', 'policy' => 'sliding_window', 'limit' => $uses, 'interval' => '1 minute'], $storage),
        );
    }

    public function testAnAddressIsBlockedOnceItHasFailedTooOften(): void
    {
        $throttle = $this->throttle(failures: 3);

        for ($i = 0; $i < 3; ++$i) {
            $throttle->assertNotBlocked('203.0.113.7');
            $throttle->failed('203.0.113.7');
        }

        $this->expectException(TooManyAttemptsException::class);
        $throttle->assertNotBlocked('203.0.113.7');
    }

    public function testCheckingDoesNotCountAsAFailure(): void
    {
        $throttle = $this->throttle(failures: 2);

        for ($i = 0; $i < 20; ++$i) {
            $throttle->assertNotBlocked('203.0.113.7');
        }

        $this->addToAssertionCount(1);
    }

    public function testAddressesAreCountedSeparately(): void
    {
        $throttle = $this->throttle(failures: 1);
        $throttle->failed('203.0.113.7');

        $throttle->assertNotBlocked('198.51.100.2');

        $this->addToAssertionCount(1);
    }

    public function testTheRetryDelayIsPositive(): void
    {
        $throttle = $this->throttle(failures: 1);
        $throttle->failed('203.0.113.7');

        try {
            $throttle->assertNotBlocked('203.0.113.7');
            self::fail('Expected a throttle.');
        } catch (TooManyAttemptsException $e) {
            self::assertGreaterThan(0, $e->retryAfter);
        }
    }

    public function testATokenHasABudget(): void
    {
        $throttle = $this->throttle(uses: 3);
        $token = Uuid::v7();

        for ($i = 0; $i < 3; ++$i) {
            $throttle->used($token);
        }

        $this->expectException(TooManyAttemptsException::class);
        $throttle->used($token);
    }

    public function testTokensHaveSeparateBudgets(): void
    {
        $throttle = $this->throttle(uses: 1);
        $throttle->used(Uuid::v7());

        $throttle->used(Uuid::v7());

        $this->addToAssertionCount(1);
    }

    public function testAMissingAddressIsStillCounted(): void
    {
        $throttle = $this->throttle(failures: 1);
        $throttle->failed(null);

        $this->expectException(TooManyAttemptsException::class);
        $throttle->assertNotBlocked(null);
    }
}
