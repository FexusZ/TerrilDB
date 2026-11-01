<?php

declare(strict_types=1);

namespace App\Tests\Http\Web\Security;

use App\Http\Web\Security\LoginThrottle;
use App\Http\Web\TooManyAttemptsException;
use PHPUnit\Framework\TestCase;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;

final class LoginThrottleTest extends TestCase
{
    private function throttle(int $perIp = 100, int $perAccount = 3): LoginThrottle
    {
        $storage = new InMemoryStorage();

        return new LoginThrottle(
            new RateLimiterFactory(['id' => 'ip', 'policy' => 'fixed_window', 'limit' => $perIp, 'interval' => '15 minutes'], $storage),
            new RateLimiterFactory(['id' => 'account', 'policy' => 'fixed_window', 'limit' => $perAccount, 'interval' => '15 minutes'], $storage),
        );
    }

    public function testAllowsAttemptsUpToTheAccountLimit(): void
    {
        $throttle = $this->throttle();

        for ($i = 0; $i < 3; ++$i) {
            $throttle->hit('203.0.113.7', 'alice');
        }

        $this->expectException(TooManyAttemptsException::class);
        $throttle->hit('203.0.113.7', 'alice');
    }

    public function testTheRetryDelayIsPositive(): void
    {
        $throttle = $this->throttle(perAccount: 1);
        $throttle->hit('203.0.113.7', 'alice');

        try {
            $throttle->hit('203.0.113.7', 'alice');
            self::fail('Expected a throttle.');
        } catch (TooManyAttemptsException $e) {
            self::assertGreaterThan(0, $e->retryAfter);
        }
    }

    public function testAccountsAreCountedSeparately(): void
    {
        $throttle = $this->throttle(perAccount: 1);

        $throttle->hit('203.0.113.7', 'alice');
        $throttle->hit('203.0.113.7', 'bob');

        $this->addToAssertionCount(1);
    }

    public function testLoginSpellingDoesNotDodgeTheLimit(): void
    {
        $throttle = $this->throttle(perAccount: 1);
        $throttle->hit('203.0.113.7', 'alice@example.test');

        $this->expectException(TooManyAttemptsException::class);
        $throttle->hit('203.0.113.7', '  ALICE@Example.Test ');
    }

    public function testTheAddressLimitStopsAnyAccountGuessing(): void
    {
        $throttle = $this->throttle(perIp: 2, perAccount: 100);

        $throttle->hit('203.0.113.7', 'a');
        $throttle->hit('203.0.113.7', 'b');

        $this->expectException(TooManyAttemptsException::class);
        $throttle->hit('203.0.113.7', 'c');
    }

    public function testASuccessfulLoginClearsTheAccountCounter(): void
    {
        $throttle = $this->throttle(perAccount: 2);
        $throttle->hit('203.0.113.7', 'alice');
        $throttle->hit('203.0.113.7', 'alice');

        $throttle->succeeded('alice');

        $throttle->hit('203.0.113.7', 'alice');
        $this->addToAssertionCount(1);
    }

    public function testAMissingAddressIsStillCounted(): void
    {
        $throttle = $this->throttle(perIp: 1);
        $throttle->hit(null, 'a');

        $this->expectException(TooManyAttemptsException::class);
        $throttle->hit(null, 'b');
    }
}
