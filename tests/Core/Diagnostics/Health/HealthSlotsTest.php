<?php

declare(strict_types=1);

namespace App\Tests\Core\Diagnostics\Health;

use App\Core\Diagnostics\Health\HealthBusyException;
use App\Core\Diagnostics\Health\HealthSlots;
use PHPUnit\Framework\TestCase;

final class HealthSlotsTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir().'/terrildb-slots-'.bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->directory.'/*') ?: []);
        @rmdir($this->directory);
    }

    public function testRunsTheWorkAndFreesTheSlot(): void
    {
        $slots = new HealthSlots($this->directory);

        self::assertSame('ok', $slots->run('user-1', static fn (): string => 'ok'));
        self::assertSame('again', $slots->run('user-1', static fn (): string => 'again'));
    }

    public function testRefusesAnOwnerWhoHoldsEveryMeasurementSlot(): void
    {
        $slots = new HealthSlots($this->directory);

        $slots->run('user-1', function () use ($slots): void {
            $slots->run('user-1', function () use ($slots): void {
                $this->expectException(HealthBusyException::class);
                $slots->run('user-1', static fn (): null => null);
            });
        });
    }

    public function testAnotherOwnerIsNotBlocked(): void
    {
        $slots = new HealthSlots($this->directory);

        $result = null;
        $slots->run('user-1', static function () use ($slots, &$result): void {
            $slots->run('user-1', static function () use ($slots, &$result): void {
                $result = $slots->run('user-2', static fn (): string => 'free');
            });
        });

        self::assertSame('free', $result);
    }

    public function testFreesTheSlotWhenTheWorkFails(): void
    {
        $slots = new HealthSlots($this->directory);

        try {
            $slots->run('user-1', static fn (): never => throw new \RuntimeException('boom'));
        } catch (\RuntimeException) {
        }
        try {
            $slots->run('user-1', static fn (): never => throw new \RuntimeException('boom'));
        } catch (\RuntimeException) {
        }

        self::assertSame('ok', $slots->run('user-1', static fn (): string => 'ok'));
    }
}
