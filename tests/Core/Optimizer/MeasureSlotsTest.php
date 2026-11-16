<?php

declare(strict_types=1);

namespace App\Tests\Core\Optimizer;

use App\Core\Optimizer\Exception\MeasureBusyException;
use App\Core\Optimizer\MeasureSlots;
use PHPUnit\Framework\TestCase;

final class MeasureSlotsTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir().'/measure-slots-'.bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory.'/*') ?: [] as $file) {
            unlink($file);
        }
        if (is_dir($this->directory)) {
            rmdir($this->directory);
        }
    }

    public function testTheThirdSimultaneousMeasureOfAnAccountIsRefused(): void
    {
        $slots = new MeasureSlots($this->directory);

        $result = $slots->run('alice', function () use ($slots): string {
            return $slots->run('alice', function () use ($slots): string {
                try {
                    $slots->run('alice', static fn (): string => 'never');
                    self::fail('The third measure should be refused.');
                } catch (MeasureBusyException $e) {
                    self::assertGreaterThan(0, $e->retryAfter);
                }

                return 'ok';
            });
        });

        self::assertSame('ok', $result);
    }

    public function testAnotherAccountKeepsItsCapacity(): void
    {
        $slots = new MeasureSlots($this->directory);

        $result = $slots->run('alice', fn (): string => $slots->run('alice', fn (): string => $slots->run('bob', static fn (): string => 'bob')));

        self::assertSame('bob', $result);
    }

    public function testThePlaceIsFreedAfterTheEnd(): void
    {
        $slots = new MeasureSlots($this->directory);
        for ($i = 0; $i < 5; ++$i) {
            self::assertSame($i, $slots->run('alice', static fn (): int => $i));
        }
    }

    public function testThePlaceIsFreedWhenTheWorkFails(): void
    {
        $slots = new MeasureSlots($this->directory);
        for ($i = 0; $i < 4; ++$i) {
            try {
                $slots->run('alice', static fn () => throw new \LogicException('timeout'));
            } catch (\LogicException) {
            }
        }

        self::assertSame('free', $slots->run('alice', static fn (): string => 'free'));
    }
}
