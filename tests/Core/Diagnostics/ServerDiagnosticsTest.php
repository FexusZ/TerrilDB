<?php

declare(strict_types=1);

namespace App\Tests\Core\Diagnostics;

use App\Core\Connection\Engine;
use App\Core\Diagnostics\DiagnosticStatus;
use App\Core\Diagnostics\Exception\DiagnosticsFailedException;
use App\Core\Diagnostics\LockDiagnostics;
use App\Core\Diagnostics\LockReport;
use App\Core\Diagnostics\ServerDiagnostics;
use App\Core\Diagnostics\SessionDiagnostics;
use App\Core\Diagnostics\SessionReport;
use App\Core\Driver\Exception\UnsupportedOperationException;
use PHPUnit\Framework\TestCase;

final class ServerDiagnosticsTest extends TestCase
{
    public function testServesTheThemesItWasGiven(): void
    {
        $sessions = new class implements SessionDiagnostics {
            public function status(): DiagnosticStatus
            {
                return DiagnosticStatus::available();
            }

            public function sessions(): SessionReport
            {
                return new SessionReport(DiagnosticStatus::available(), [], false);
            }
        };

        self::assertSame($sessions, (new ServerDiagnostics(Engine::MySql, $sessions))->sessions());
    }

    public function testAThemeTheEngineDoesNotKnowIsUnsupported(): void
    {
        $this->expectException(UnsupportedOperationException::class);

        (new ServerDiagnostics(Engine::MySql))->sessions();
    }

    public function testCapabilitiesSayWhichThemesAreServed(): void
    {
        $diagnostics = new ServerDiagnostics(Engine::Redis);

        self::assertSame(['sessions' => 'unsupported', 'locks' => 'unsupported'], array_map(static fn (DiagnosticStatus $s): string => $s->availability->value, $diagnostics->capabilities()));
    }

    public function testCapabilitiesAskTheThemeForItsStatus(): void
    {
        $sessions = new class implements SessionDiagnostics {
            public function status(): DiagnosticStatus
            {
                return DiagnosticStatus::denied('PROCESS');
            }

            public function sessions(): SessionReport
            {
                throw new \LogicException('Not needed to tell the status.');
            }
        };

        $status = (new ServerDiagnostics(Engine::MySql, $sessions))->capabilities()['sessions'];

        self::assertSame('PROCESS', $status->privilege);
    }

    public function testLocksAreServedOrUnsupported(): void
    {
        $locks = new class implements LockDiagnostics {
            public function status(): DiagnosticStatus
            {
                return DiagnosticStatus::disabled('performance_schema_off');
            }

            public function locks(): LockReport
            {
                return new LockReport(DiagnosticStatus::available(), [], [], false);
            }
        };

        $diagnostics = new ServerDiagnostics(Engine::MySql, locks: $locks);

        self::assertSame($locks, $diagnostics->locks());
        self::assertSame('disabled', $diagnostics->capabilities()['locks']->availability->value);
        $this->expectException(UnsupportedOperationException::class);
        (new ServerDiagnostics(Engine::ClickHouse))->locks();
    }

    private function brokenSessions(): SessionDiagnostics
    {
        return new class implements SessionDiagnostics {
            public function status(): DiagnosticStatus
            {
                throw DiagnosticsFailedException::create(new \RuntimeException("connection lost to 'db.internal' for user jean"));
            }

            public function sessions(): SessionReport
            {
                throw new \LogicException('Not read here.');
            }
        };
    }

    private function healthyLocks(): LockDiagnostics
    {
        return new class implements LockDiagnostics {
            public function status(): DiagnosticStatus
            {
                return DiagnosticStatus::available();
            }

            public function locks(): LockReport
            {
                throw new \LogicException('Not read here.');
            }
        };
    }

    public function testAThemeThatFailsDoesNotHideTheOthers(): void
    {
        $capabilities = (new ServerDiagnostics(Engine::PostgreSql, $this->brokenSessions(), $this->healthyLocks()))->capabilities();

        self::assertSame('error', $capabilities['sessions']->availability->value);
        self::assertSame('check_failed', $capabilities['sessions']->reason);
        self::assertSame('available', $capabilities['locks']->availability->value);
    }

    public function testTheFailureIsHandedToTheCallerWithItsThemeSoItCanBeLogged(): void
    {
        $seen = [];

        (new ServerDiagnostics(Engine::PostgreSql, $this->brokenSessions(), $this->healthyLocks()))->capabilities(static function (string $theme, DiagnosticsFailedException $e) use (&$seen): void {
            $seen[] = [$theme, $e->getPrevious()?->getMessage()];
        });

        self::assertSame([['sessions', "connection lost to 'db.internal' for user jean"]], $seen);
    }
}
