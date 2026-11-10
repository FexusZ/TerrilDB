<?php

declare(strict_types=1);

namespace App\Tests\Http\Web\Security;

use App\Command\LiftBanCommand;
use App\Http\Json\TooManyAttemptsException;
use App\Http\Web\Security\LoginLockout;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Console\Tester\CommandTester;

final class LiftBanCommandTest extends TestCase
{
    private LoginLockout $lockout;

    protected function setUp(): void
    {
        $this->lockout = new LoginLockout(new ArrayAdapter(), new MockClock('2026-01-01 10:00:00 UTC'));
        foreach (['alice', 'bob', 'carol'] as $login) {
            for ($i = 0; $i < 5; ++$i) {
                $this->lockout->failed('203.0.113.7', $login);
            }
        }
    }

    public function testLiftsTheBanOfTheGivenAddress(): void
    {
        $tester = new CommandTester(new LiftBanCommand($this->lockout));

        $status = $tester->execute(['address' => '203.0.113.7']);

        self::assertSame(0, $status);
        $this->lockout->check('203.0.113.7', 'dave');
    }

    public function testKeepsTheBanWhenTheAddressIsNotOne(): void
    {
        $tester = new CommandTester(new LiftBanCommand($this->lockout));

        $status = $tester->execute(['address' => 'pas une adresse']);

        self::assertSame(1, $status);
        $this->expectException(TooManyAttemptsException::class);
        $this->lockout->check('203.0.113.7', 'dave');
    }
}
