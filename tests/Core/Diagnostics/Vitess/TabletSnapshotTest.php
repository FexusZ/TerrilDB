<?php

declare(strict_types=1);

namespace App\Tests\Core\Diagnostics\Vitess;

use App\Core\Connection\Engine;
use App\Core\Diagnostics\DiagnosticStatus;
use App\Core\Diagnostics\Exception\DiagnosticsFailedException;
use App\Core\Diagnostics\Replication\ReplicationReport;
use App\Core\Diagnostics\ReplicationDiagnostics;
use App\Core\Diagnostics\ServerDiagnostics;
use App\Core\Diagnostics\VariableDiagnostics;
use App\Core\Diagnostics\VariableQuery;
use App\Core\Diagnostics\VariableReport;
use App\Core\Diagnostics\Vitess\TabletSnapshot;
use App\Core\Diagnostics\Vitess\VitessTablet;
use PHPUnit\Framework\TestCase;

final class TabletSnapshotTest extends TestCase
{
    private function tablet(): VitessTablet
    {
        return new VitessTablet('zone1-101', 'zone1', 'shop', '0', 'REPLICA', true);
    }

    private function replication(bool $fails): ReplicationDiagnostics
    {
        return new class($fails) implements ReplicationDiagnostics {
            public function __construct(private readonly bool $fails)
            {
            }

            public function status(): DiagnosticStatus
            {
                return DiagnosticStatus::available();
            }

            public function replication(): ReplicationReport
            {
                return $this->fails ? throw new DiagnosticsFailedException('boom') : ReplicationReport::unreadable(DiagnosticStatus::unsupported('x'));
            }
        };
    }

    private function variables(\stdClass $spy): VariableDiagnostics
    {
        return new class($spy) implements VariableDiagnostics {
            public function __construct(private readonly \stdClass $spy)
            {
            }

            public function status(): DiagnosticStatus
            {
                return DiagnosticStatus::available();
            }

            public function variables(VariableQuery $query): VariableReport
            {
                $this->spy->asked = $query;

                return new VariableReport(DiagnosticStatus::available(), [], false);
            }
        };
    }

    public function testItReadsReplicationAndTheChangedVariables(): void
    {
        $spy = new \stdClass();
        $snapshot = TabletSnapshot::read($this->tablet(), new ServerDiagnostics(Engine::MySql, variables: $this->variables($spy), replication: $this->replication(false)));

        self::assertNull($snapshot->failure);
        self::assertNotNull($snapshot->replication);
        self::assertNotNull($snapshot->variables);
        self::assertInstanceOf(VariableQuery::class, $spy->asked);
        self::assertTrue($spy->asked->changedOnly);
    }

    public function testATopicThatFailsLeavesTheOtherReadable(): void
    {
        $snapshot = TabletSnapshot::read($this->tablet(), new ServerDiagnostics(Engine::MySql, variables: $this->variables(new \stdClass()), replication: $this->replication(true)));

        self::assertNull($snapshot->replication);
        self::assertNotNull($snapshot->variables);
        self::assertNull($snapshot->failure);
    }

    public function testATabletWithNothingReadableIsReportedAsUnreadable(): void
    {
        $snapshot = TabletSnapshot::read($this->tablet(), new ServerDiagnostics(Engine::MySql));

        self::assertSame('unreadable', $snapshot->failure);
    }
}
