<?php

declare(strict_types=1);

namespace App\Tests\Core\Diagnostics;

use App\Core\Connection\Engine;
use App\Core\Diagnostics\AccountDiagnostics;
use App\Core\Diagnostics\AccountReport;
use App\Core\Diagnostics\ConnectionEncryption;
use App\Core\Diagnostics\DiagnosticStatus;
use App\Core\Diagnostics\DigestOrder;
use App\Core\Diagnostics\DigestQuery;
use App\Core\Diagnostics\DigestReport;
use App\Core\Diagnostics\EncryptionDiagnostics;
use App\Core\Diagnostics\Exception\DiagnosticsFailedException;
use App\Core\Diagnostics\Health\HealthSample;
use App\Core\Diagnostics\Health\HealthWindow;
use App\Core\Diagnostics\HealthDiagnostics;
use App\Core\Diagnostics\LockDiagnostics;
use App\Core\Diagnostics\LockReport;
use App\Core\Diagnostics\Log\ErrorLogReport;
use App\Core\Diagnostics\Log\SlowQueryReport;
use App\Core\Diagnostics\LogDiagnostics;
use App\Core\Diagnostics\ServerDiagnostics;
use App\Core\Diagnostics\SessionDiagnostics;
use App\Core\Diagnostics\SessionReport;
use App\Core\Diagnostics\StatementDiagnostics;
use App\Core\Diagnostics\TableHolders;
use App\Core\Diagnostics\TabletDiagnostics;
use App\Core\Diagnostics\VariableDiagnostics;
use App\Core\Diagnostics\VariableQuery;
use App\Core\Diagnostics\VariableReport;
use App\Core\Diagnostics\Vitess\VitessTablet;
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

        self::assertSame(['sessions' => 'unsupported', 'locks' => 'unsupported', 'statements' => 'unsupported', 'variables' => 'unsupported', 'accounts' => 'unsupported', 'encryption' => 'unsupported', 'health' => 'unsupported', 'replication' => 'unsupported', 'logs' => 'unsupported'], array_map(static fn (DiagnosticStatus $s): string => $s->availability->value, $diagnostics->capabilities()));
    }

    public function testAnEngineCanGiveTheReasonWhyNoThemeIsServed(): void
    {
        $diagnostics = new ServerDiagnostics(Engine::MySql, unsupportedReason: 'vitess_tablet_scope');

        foreach ($diagnostics->capabilities() as $theme => $status) {
            self::assertSame('unsupported', $status->availability->value, $theme);
            self::assertSame('vitess_tablet_scope', $status->reason, $theme);
        }
        self::assertSame('not_supported', (new ServerDiagnostics(Engine::MySql))->capabilities()['sessions']->reason, 'sans raison donnée, la raison d\'avant');
    }

    public function testTheTabletListIsAThemeOfItsOwnThatStaysAvailableWhenTheOthersAreNot(): void
    {
        $tablets = new class implements TabletDiagnostics {
            public function status(): DiagnosticStatus
            {
                return DiagnosticStatus::available();
            }

            public function tablets(): array
            {
                return $this->listing()->tablets;
            }

            public function listing(): \App\Core\Diagnostics\Vitess\TabletListing
            {
                return new \App\Core\Diagnostics\Vitess\TabletListing([new VitessTablet('test-0000000002', 'test', 'shop', '0', 'REPLICA', true)]);
            }
        };
        $diagnostics = new ServerDiagnostics(Engine::MySql, tablets: $tablets, unsupportedReason: 'vitess_tablet_scope');

        $capabilities = $diagnostics->capabilities();

        self::assertSame('available', $capabilities['tablets']->availability->value);
        self::assertSame('unsupported', $capabilities['sessions']->availability->value);
        self::assertSame('test-0000000002', $diagnostics->tablets()->tablets()[0]->alias);
    }

    public function testWithoutTabletsNoSuchThemeIsAnnounced(): void
    {
        $diagnostics = new ServerDiagnostics(Engine::MySql);

        self::assertArrayNotHasKey('tablets', $diagnostics->capabilities());
        $this->expectException(UnsupportedOperationException::class);
        $diagnostics->tablets();
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

            public function tableHolders(string $table): TableHolders
            {
                return new TableHolders(DiagnosticStatus::available(), []);
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

            public function tableHolders(string $table): TableHolders
            {
                return new TableHolders(DiagnosticStatus::available(), []);
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

    public function testStatementsAreServedOrUnsupported(): void
    {
        $statements = new class implements StatementDiagnostics {
            public function status(): DiagnosticStatus
            {
                return DiagnosticStatus::disabled('extension_missing');
            }

            public function top(DigestQuery $query): DigestReport
            {
                return new DigestReport(DiagnosticStatus::available(), $query->order, []);
            }
        };

        $diagnostics = new ServerDiagnostics(Engine::PostgreSql, statements: $statements);

        self::assertSame($statements, $diagnostics->statements());
        self::assertSame('disabled', $diagnostics->capabilities()['statements']->availability->value);
        self::assertSame(DigestOrder::TotalTime, $statements->top(DigestQuery::fromInput(null, null))->order);
        $this->expectException(UnsupportedOperationException::class);
        (new ServerDiagnostics(Engine::Redis))->statements();
    }

    public function testVariablesAreServedOrUnsupported(): void
    {
        $variables = new class implements VariableDiagnostics {
            public function status(): DiagnosticStatus
            {
                return DiagnosticStatus::disabled('performance_schema_off');
            }

            public function variables(VariableQuery $query): VariableReport
            {
                return new VariableReport(DiagnosticStatus::available(), [], false);
            }
        };

        $diagnostics = new ServerDiagnostics(Engine::MySql, variables: $variables);

        self::assertSame($variables, $diagnostics->variables());
        self::assertSame('performance_schema_off', $diagnostics->capabilities()['variables']->reason);

        $this->expectException(UnsupportedOperationException::class);
        (new ServerDiagnostics(Engine::Redis))->variables();
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

    public function testHealthIsServedOrUnsupported(): void
    {
        $health = new class implements HealthDiagnostics {
            public function status(): DiagnosticStatus
            {
                return DiagnosticStatus::available();
            }

            public function sample(): HealthSample
            {
                return new HealthSample(0.0, []);
            }

            public function indicators(HealthWindow $window): array
            {
                return [];
            }
        };

        $diagnostics = new ServerDiagnostics(Engine::MySql, health: $health);

        self::assertSame($health, $diagnostics->health());
        self::assertSame('available', $diagnostics->capabilities()['health']->availability->value);
        $this->expectException(UnsupportedOperationException::class);
        (new ServerDiagnostics(Engine::Redis))->health();
    }

    public function testLogsAreServedOrUnsupported(): void
    {
        $logs = new class implements LogDiagnostics {
            public function status(): DiagnosticStatus
            {
                return DiagnosticStatus::disabled('slow_query_log_off');
            }

            public function errorLog(): ErrorLogReport
            {
                return new ErrorLogReport(DiagnosticStatus::available(), [], false);
            }

            public function slowQueries(): SlowQueryReport
            {
                return new SlowQueryReport(DiagnosticStatus::available(), [], false, null);
            }
        };

        $diagnostics = new ServerDiagnostics(Engine::MySql, logs: $logs);

        self::assertSame($logs, $diagnostics->logs());
        self::assertSame('disabled', $diagnostics->capabilities()['logs']->availability->value);

        $this->expectException(UnsupportedOperationException::class);
        (new ServerDiagnostics(Engine::Redis))->logs();
    }
}
