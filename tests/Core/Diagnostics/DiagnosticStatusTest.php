<?php

declare(strict_types=1);

namespace App\Tests\Core\Diagnostics;

use App\Core\Diagnostics\Availability;
use App\Core\Diagnostics\DiagnosticStatus;
use PHPUnit\Framework\TestCase;

final class DiagnosticStatusTest extends TestCase
{
    public function testAvailableCarriesNoReason(): void
    {
        $status = DiagnosticStatus::available();

        self::assertSame(Availability::Available, $status->availability);
        self::assertNull($status->reason);
        self::assertNull($status->privilege);
        self::assertTrue($status->isAvailable());
    }

    public function testDeniedNamesTheMissingPrivilege(): void
    {
        $status = DiagnosticStatus::denied('PROCESS');

        self::assertSame(Availability::PermissionDenied, $status->availability);
        self::assertSame('insufficient_privilege', $status->reason);
        self::assertSame('PROCESS', $status->privilege);
        self::assertFalse($status->isAvailable());
    }

    public function testDisabledAndUnsupportedGiveAStableReason(): void
    {
        $disabled = DiagnosticStatus::disabled('performance_schema_off');
        $unsupported = DiagnosticStatus::unsupported('engine_has_no_sessions');

        self::assertSame(Availability::Disabled, $disabled->availability);
        self::assertSame('performance_schema_off', $disabled->reason);
        self::assertSame(Availability::Unsupported, $unsupported->availability);
        self::assertSame('engine_has_no_sessions', $unsupported->reason);
    }

    public function testAFailedCheckIsAnErrorStateWithAStableReasonAndNothingFromTheEngine(): void
    {
        $status = DiagnosticStatus::failed();

        self::assertSame(Availability::Error, $status->availability);
        self::assertSame('check_failed', $status->reason);
        self::assertNull($status->privilege);
        self::assertFalse($status->isAvailable());
    }

    public function testAReasonIsAStableCodeNotFreeText(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        DiagnosticStatus::disabled('The engine said: password for user x');
    }

    public function testAPrivilegeIsAKeywordNotFreeText(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        DiagnosticStatus::denied('SELECT ON secret.table; DROP');
    }

    public function testValuesAreTheNamesOfTheContract(): void
    {
        self::assertSame(['available', 'unsupported', 'permission_denied', 'disabled', 'error'], array_map(static fn (Availability $a): string => $a->value, Availability::cases()));
    }
}
