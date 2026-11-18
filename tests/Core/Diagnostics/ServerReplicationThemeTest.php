<?php

declare(strict_types=1);

namespace App\Tests\Core\Diagnostics;

use App\Core\Connection\Engine;
use App\Core\Diagnostics\DiagnosticStatus;
use App\Core\Diagnostics\Replication\ReplicationReport;
use App\Core\Diagnostics\ReplicationDiagnostics;
use App\Core\Diagnostics\ServerDiagnostics;
use App\Core\Driver\Exception\UnsupportedOperationException;
use PHPUnit\Framework\TestCase;

final class ServerReplicationThemeTest extends TestCase
{
    public function testReplicationIsServedOrUnsupported(): void
    {
        $replication = new class implements ReplicationDiagnostics {
            public function status(): DiagnosticStatus
            {
                return DiagnosticStatus::denied('REPLICATION CLIENT');
            }

            public function replication(): ReplicationReport
            {
                throw new \LogicException('Not needed to tell the status.');
            }
        };

        $diagnostics = new ServerDiagnostics(Engine::MySql, replication: $replication);

        self::assertSame($replication, $diagnostics->replication());
        self::assertSame('permission_denied', $diagnostics->capabilities()['replication']->availability->value);
        self::assertSame('unsupported', (new ServerDiagnostics(Engine::Redis))->capabilities()['replication']->availability->value);
        $this->expectException(UnsupportedOperationException::class);
        (new ServerDiagnostics(Engine::ClickHouse))->replication();
    }

    public function testAnUnreadableReportCarriesNoRoleAndNoAlert(): void
    {
        $report = ReplicationReport::unreadable(DiagnosticStatus::unsupported('version_not_supported'));

        self::assertSame('none', $report->role->value);
        self::assertSame([], $report->channels);
        self::assertNull($report->replicaCount);
        self::assertSame([], $report->alerts());
    }
}
