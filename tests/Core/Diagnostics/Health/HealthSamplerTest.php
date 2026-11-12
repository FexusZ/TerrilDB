<?php

declare(strict_types=1);

namespace App\Tests\Core\Diagnostics\Health;

use App\Core\Diagnostics\DiagnosticStatus;
use App\Core\Diagnostics\Health\HealthIndicator;
use App\Core\Diagnostics\Health\HealthLevel;
use App\Core\Diagnostics\Health\HealthSample;
use App\Core\Diagnostics\Health\HealthSampler;
use App\Core\Diagnostics\Health\HealthWindow;
use App\Core\Diagnostics\Health\Pause;
use App\Core\Diagnostics\HealthDiagnostics;
use App\Core\Query\Exception\InvalidQueryOptionsException;
use PHPUnit\Framework\TestCase;

final class HealthSamplerTest extends TestCase
{
    /**
     * @param list<HealthSample> $samples
     */
    private static function source(array $samples, ?DiagnosticStatus $status = null): ScriptedHealth
    {
        return new ScriptedHealth($samples, $status ?? DiagnosticStatus::available());
    }

    private static function pause(): RecordingPause
    {
        return new RecordingPause();
    }

    public function testTakesTwoSamplesAndComputesTheRates(): void
    {
        $pause = self::pause();
        $source = self::source([new HealthSample(0.0, ['Questions' => '10'], 5), new HealthSample(5.0, ['Questions' => '60'], 10)]);

        $report = (new HealthSampler($pause))->measure($source, 5);

        self::assertSame([5.0], $pause->slept);
        self::assertSame(2, $source->reads);
        self::assertTrue($report->status->isAvailable());
        self::assertNull($report->discarded);
        self::assertSame(5.0, $report->seconds);
        self::assertSame(10.0, $report->indicators[0]->value);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('badIntervals')]
    public function testTheIntervalIsBounded(int $seconds): void
    {
        $this->expectException(InvalidQueryOptionsException::class);

        (new HealthSampler(self::pause()))->measure(self::source([]), $seconds);
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function badIntervals(): iterable
    {
        yield 'zero' => [0];
        yield 'negative' => [-3];
        yield 'too long' => [31];
    }

    public function testBoundsAreAccepted(): void
    {
        foreach ([1, 30] as $seconds) {
            $report = (new HealthSampler(self::pause()))->measure(self::source([new HealthSample(0.0, [], 1), new HealthSample((float) $seconds, [], 1 + $seconds)]), $seconds);
            self::assertNull($report->discarded);
        }
    }

    public function testAUnreadableSourceTakesNoSampleAndDoesNotWait(): void
    {
        $pause = self::pause();
        $source = self::source([], DiagnosticStatus::denied('PROCESS'));

        $report = (new HealthSampler($pause))->measure($source, 5);

        self::assertSame([], $pause->slept);
        self::assertSame(0, $source->reads);
        self::assertSame('permission_denied', $report->status->availability->value);
        self::assertSame([], $report->indicators);
    }

    public function testARestartedServerDiscardsTheMeasurement(): void
    {
        $source = self::source([new HealthSample(0.0, ['Questions' => '9000'], 5000), new HealthSample(5.0, ['Questions' => '3'], 2)]);

        $report = (new HealthSampler(self::pause()))->measure($source, 5);

        self::assertSame('counters_reset', $report->discarded);
        self::assertSame([], $report->indicators);
    }
}

final class RecordingPause implements Pause
{
    /** @var list<float> */
    public array $slept = [];

    public function sleep(float $seconds): void
    {
        $this->slept[] = $seconds;
    }
}

final class ScriptedHealth implements HealthDiagnostics
{
    public int $reads = 0;

    /**
     * @param list<HealthSample> $samples
     */
    public function __construct(private readonly array $samples, private readonly DiagnosticStatus $status)
    {
    }

    public function status(): DiagnosticStatus
    {
        return $this->status;
    }

    public function sample(): HealthSample
    {
        return $this->samples[$this->reads++];
    }

    public function indicators(HealthWindow $window): array
    {
        return [new HealthIndicator('q', $window->rate('Questions'), 'per_second', HealthLevel::Ok, null)];
    }
}
