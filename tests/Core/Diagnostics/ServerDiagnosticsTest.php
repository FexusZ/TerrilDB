<?php

declare(strict_types=1);

namespace App\Tests\Core\Diagnostics;

use App\Core\Connection\Engine;
use App\Core\Diagnostics\AccountDiagnostics;
use App\Core\Diagnostics\AccountReport;
use App\Core\Diagnostics\ConnectionEncryption;
use App\Core\Diagnostics\DiagnosticStatus;
use App\Core\Diagnostics\EncryptionDiagnostics;
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

        self::assertSame(['sessions' => 'unsupported', 'locks' => 'unsupported', 'accounts' => 'unsupported', 'encryption' => 'unsupported'], array_map(static fn (DiagnosticStatus $s): string => $s->availability->value, $diagnostics->capabilities()));
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

    public function testAccountsAndEncryptionAreServedOrUnsupported(): void
    {
        $accounts = new class implements AccountDiagnostics {
            public function status(): DiagnosticStatus
            {
                return DiagnosticStatus::denied('SELECT ON mysql');
            }

            public function accounts(): AccountReport
            {
                return new AccountReport(DiagnosticStatus::available(), Engine::MySql, [], false);
            }
        };
        $encryption = new class implements EncryptionDiagnostics {
            public function status(): DiagnosticStatus
            {
                return DiagnosticStatus::available();
            }

            public function encryption(): ConnectionEncryption
            {
                return new ConnectionEncryption(true, 'TLSv1.3', 'TLS_AES_256_GCM_SHA384');
            }
        };

        $diagnostics = new ServerDiagnostics(Engine::MySql, accounts: $accounts, encryption: $encryption);

        self::assertSame($accounts, $diagnostics->accounts());
        self::assertSame($encryption, $diagnostics->encryption());
        self::assertSame('SELECT ON mysql', $diagnostics->capabilities()['accounts']->privilege);
        self::assertSame('available', $diagnostics->capabilities()['encryption']->availability->value);
        $this->expectException(UnsupportedOperationException::class);
        (new ServerDiagnostics(Engine::Redis))->accounts();
    }
}
