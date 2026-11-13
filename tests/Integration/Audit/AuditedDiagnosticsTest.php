<?php

declare(strict_types=1);

namespace App\Tests\Integration\Audit;

use App\Audit\AuditedDiagnostics;
use App\Audit\AuditLog;
use App\Core\Connection\Engine;
use App\Core\Diagnostics\DiagnosticStatus;
use App\Core\Diagnostics\Exception\DiagnosticsFailedException;
use App\Core\Diagnostics\LockDiagnostics;
use App\Core\Diagnostics\LockReport;
use App\Core\Diagnostics\ServerDiagnostics;
use App\Core\Diagnostics\SessionDiagnostics;
use App\Core\Diagnostics\SessionReport;
use App\Core\Diagnostics\TableHolders;
use App\Core\Driver\TargetConnection;
use App\Core\Guard\StatementKind;
use App\Core\Query\QueryOptions;
use App\Core\Query\QueryResult;
use App\Core\Query\QueryRunner;
use App\Repository\AuditEventRepository;
use App\Tests\Integration\Persistence\DatabaseTestCase;
use PHPUnit\Framework\Attributes\Group;
use Psr\Log\AbstractLogger;
use Symfony\Component\Clock\MockClock;

#[Group('integration')]
final class AuditedDiagnosticsTest extends DatabaseTestCase
{
    public function testAThemeThatFailsIsLoggedByNameAndClassButNeverByTheEngineMessage(): void
    {
        $events = self::getContainer()->get(AuditEventRepository::class);
        self::assertInstanceOf(AuditEventRepository::class, $events);
        $sessions = new class implements SessionDiagnostics {
            public function status(): DiagnosticStatus
            {
                throw DiagnosticsFailedException::create(new \RuntimeException("connection lost to 'db.internal' for jean@example.org"));
            }

            public function sessions(): SessionReport
            {
                throw new \LogicException('Not read here.');
            }
        };
        $locks = new class implements LockDiagnostics {
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
        $logger = new class extends AbstractLogger {
            /** @var list<array{string, string, array<mixed>}> */
            public array $records = [];

            public function log(mixed $level, string|\Stringable $message, array $context = []): void
            {
                $this->records[] = [\is_string($level) ? $level : '', (string) $message, $context];
            }
        };
        $runner = new class implements QueryRunner {
            public function run(string $sql, StatementKind $kind, QueryOptions $options): QueryResult
            {
                throw new \LogicException('Not used here.');
            }
        };
        $target = new TargetConnection(Engine::PostgreSql, null, $runner, static function (): void {}, diagnostics: new ServerDiagnostics(Engine::PostgreSql, $sessions, $locks));

        $capabilities = (new AuditedDiagnostics(new AuditLog($events, new MockClock('2026-11-01 10:00:00 UTC')), $logger))->capabilities($target);

        self::assertSame('error', $capabilities['sessions']->availability->value);
        self::assertSame('available', $capabilities['locks']->availability->value);
        self::assertCount(1, $logger->records);
        self::assertSame('error', $logger->records[0][0]);
        self::assertSame('sessions', $logger->records[0][2]['theme'] ?? null);
        self::assertSame(\RuntimeException::class, $logger->records[0][2]['reason'] ?? null);
        self::assertStringNotContainsString('jean', json_encode($logger->records, \JSON_THROW_ON_ERROR));
        self::assertStringNotContainsString('db.internal', json_encode($logger->records, \JSON_THROW_ON_ERROR));
    }
}
