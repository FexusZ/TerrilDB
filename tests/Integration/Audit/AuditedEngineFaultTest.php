<?php

declare(strict_types=1);

namespace App\Tests\Integration\Audit;

use App\Audit\AuditAction;
use App\Audit\AuditActor;
use App\Audit\AuditContext;
use App\Audit\AuditedQueries;
use App\Audit\AuditLog;
use App\Audit\AuditOutcome;
use App\Core\Connection\Engine;
use App\Core\Driver\DetailedErrors;
use App\Core\Driver\EngineFault;
use App\Core\Driver\Exception\EngineFaultException;
use App\Core\Driver\TargetConnection;
use App\Core\Guard\StatementGuard;
use App\Core\Guard\StatementKind;
use App\Core\Query\QueryMode;
use App\Core\Query\QueryOptions;
use App\Core\Query\QueryResult;
use App\Core\Query\QueryRunner;
use App\Core\Query\QueryService;
use App\Repository\AuditEventRepository;
use App\Tests\Integration\Persistence\DatabaseTestCase;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Uid\Uuid;

#[Group('integration')]
final class AuditedEngineFaultTest extends DatabaseTestCase
{
    public function testAnEngineFaultIsRecordedWithItsStableReasonAndNoEngineMessage(): void
    {
        $events = self::getContainer()->get(AuditEventRepository::class);
        self::assertInstanceOf(AuditEventRepository::class, $events);
        $runner = new class implements QueryRunner {
            public function run(string $sql, StatementKind $kind, QueryOptions $options): QueryResult
            {
                throw new EngineFaultException(EngineFault::DuplicateKey, new \RuntimeException("Duplicate entry 'jean@example.org' for key 'email'"));
            }
        };
        $target = new TargetConnection(Engine::MySql, null, $runner, static function (): void {});
        $user = Uuid::v7();
        $queries = new AuditedQueries(new QueryService(new StatementGuard()), new AuditLog($events, new MockClock('2026-11-01 10:00:00 UTC')), new DetailedErrors(true));

        try {
            $queries->execute(new AuditContext(AuditActor::Web, $user, null, null), Uuid::v7(), $target, "INSERT INTO t (email) VALUES ('x')", new QueryOptions(mode: QueryMode::ReadWrite), true);
            self::fail('The fault should reach the caller.');
        } catch (EngineFaultException) {
        }
        $this->entityManager->clear();

        $event = $events->findForUser($user)[0];
        self::assertSame(AuditAction::QueryFailed, $event->getAction());
        self::assertSame(AuditOutcome::Failed, $event->getOutcome());
        self::assertSame('duplicate_key', $event->getDetails()['reason']);
        self::assertArrayNotHasKey('error', $event->getDetails());
    }

    public function testWithoutTheSettingTheJournalKeepsItsOriginalFailureRecord(): void
    {
        $events = self::getContainer()->get(AuditEventRepository::class);
        self::assertInstanceOf(AuditEventRepository::class, $events);
        $runner = new class implements QueryRunner {
            public function run(string $sql, StatementKind $kind, QueryOptions $options): QueryResult
            {
                throw new EngineFaultException(EngineFault::PermissionDenied, new \RuntimeException('raw'), 'SELECT command denied to user');
            }
        };
        $target = new TargetConnection(Engine::MySql, null, $runner, static function (): void {});
        $user = Uuid::v7();
        $queries = new AuditedQueries(new QueryService(new StatementGuard()), new AuditLog($events, new MockClock('2026-11-01 10:00:00 UTC')));

        try {
            $queries->execute(new AuditContext(AuditActor::Web, $user, null, null), Uuid::v7(), $target, 'SELECT 1', new QueryOptions(), false);
            self::fail('The fault should reach the caller.');
        } catch (EngineFaultException) {
        }
        $this->entityManager->clear();

        $details = $events->findForUser($user)[0]->getDetails();
        self::assertSame('engine_error', $details['reason']);
        self::assertSame('SELECT command denied to user', $details['error']);
    }
}
