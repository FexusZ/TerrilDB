<?php

declare(strict_types=1);

namespace App\Tests\Integration\Audit;

use App\Audit\AuditActor;
use App\Audit\AuditContext;
use App\Audit\AuditedQueries;
use App\Audit\AuditLog;
use App\Core\Connection\Engine;
use App\Core\Driver\TargetConnection;
use App\Core\Guard\StatementGuard;
use App\Core\Guard\StatementKind;
use App\Core\Query\Exception\QueryFailedException;
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
final class AuditedEngineErrorTest extends DatabaseTestCase
{
    /**
     * @return array<string, mixed>
     */
    private function failWith(bool $recordEngineError): array
    {
        $events = self::getContainer()->get(AuditEventRepository::class);
        self::assertInstanceOf(AuditEventRepository::class, $events);
        $runner = new class implements QueryRunner {
            public function run(string $sql, StatementKind $kind, QueryOptions $options): QueryResult
            {
                throw new QueryFailedException("syntax error at or near 'jean@example.org'");
            }
        };
        $target = new TargetConnection(Engine::PostgreSql, null, $runner, static function (): void {});
        $user = Uuid::v7();
        $queries = new AuditedQueries(new QueryService(new StatementGuard()), new AuditLog($events, new MockClock('2026-11-01 10:00:00 UTC')));

        try {
            $queries->execute(new AuditContext(AuditActor::Web, $user, null, null), Uuid::v7(), $target, 'SELECT 1', new QueryOptions(), recordEngineError: $recordEngineError);
            self::fail('The failure should reach the caller.');
        } catch (QueryFailedException) {
        }
        $this->entityManager->clear();

        return $events->findForUser($user)[0]->getDetails();
    }

    public function testTheEngineMessageIsRecordedByDefault(): void
    {
        $details = $this->failWith(true);

        self::assertSame('engine_error', $details['reason']);
        self::assertIsString($details['error']);
        self::assertStringContainsString('syntax error', $details['error']);
    }

    public function testTheEngineMessageCanBeKeptOutOfTheJournal(): void
    {
        $details = $this->failWith(false);

        self::assertSame('engine_error', $details['reason']);
        self::assertArrayNotHasKey('error', $details);
    }
}
