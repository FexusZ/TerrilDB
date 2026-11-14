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
use App\Core\Driver\Exception\LockConflictException;
use App\Core\Driver\LockConflict;
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
final class AuditedLockConflictTest extends DatabaseTestCase
{
    public function testALockConflictIsRecordedWithItsStableReasonAndNoEngineMessage(): void
    {
        $events = self::getContainer()->get(AuditEventRepository::class);
        self::assertInstanceOf(AuditEventRepository::class, $events);
        $runner = new class implements QueryRunner {
            public function run(string $sql, StatementKind $kind, QueryOptions $options): QueryResult
            {
                throw new LockConflictException(LockConflict::Deadlock, new \RuntimeException("Deadlock on 'jean@example.org'"));
            }
        };
        $target = new TargetConnection(Engine::PostgreSql, null, $runner, static function (): void {});
        $user = Uuid::v7();
        $queries = new AuditedQueries(new QueryService(new StatementGuard()), new AuditLog($events, new MockClock('2026-11-01 10:00:00 UTC')));

        try {
            $queries->execute(new AuditContext(AuditActor::Web, $user, null, null), Uuid::v7(), $target, 'UPDATE t SET a = 1 WHERE id = 2', new QueryOptions(mode: QueryMode::ReadWrite), true);
            self::fail('The conflict should reach the caller.');
        } catch (LockConflictException) {
        }
        $this->entityManager->clear();

        $event = $events->findForUser($user)[0];
        self::assertSame(AuditAction::QueryFailed, $event->getAction());
        self::assertSame(AuditOutcome::Failed, $event->getOutcome());
        self::assertSame('deadlock', $event->getDetails()['reason']);
        self::assertArrayNotHasKey('error', $event->getDetails());
    }
}
