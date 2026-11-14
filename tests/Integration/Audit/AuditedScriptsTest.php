<?php

declare(strict_types=1);

namespace App\Tests\Integration\Audit;

use App\Audit\AuditActor;
use App\Audit\AuditContext;
use App\Audit\AuditedScripts;
use App\Audit\AuditLog;
use App\Core\Connection\Engine;
use App\Core\Driver\TargetConnection;
use App\Core\Guard\ScriptAnalyzer;
use App\Core\Query\QueryRunner;
use App\Core\Query\Script\ScriptExecutor;
use App\Core\Query\Script\ScriptOptions;
use App\Core\Query\Script\ScriptResult;
use App\Core\Query\Script\ScriptService;
use App\Core\Query\WritePermissions;
use App\Repository\AuditEventRepository;
use App\Tests\Integration\Persistence\DatabaseTestCase;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Uid\Uuid;

#[Group('integration')]
final class AuditedScriptsTest extends DatabaseTestCase
{
    public function testAnExecutorThatBreaksOutsideTheEngineStillLeavesAnAuditLine(): void
    {
        $events = self::getContainer()->get(AuditEventRepository::class);
        self::assertInstanceOf(AuditEventRepository::class, $events);
        $executor = new class implements ScriptExecutor {
            public function nonTransactionalTables(): array
            {
                return [];
            }

            public function execute(array $statements, int $timeoutMs, bool $dryRun): ScriptResult
            {
                throw new \RuntimeException('secret value 4242');
            }
        };
        $target = new TargetConnection(Engine::PostgreSql, null, $this->createStub(QueryRunner::class), static function (): void {
        }, script: $executor);
        $user = Uuid::v7();
        $audited = new AuditedScripts(new ScriptService(new ScriptAnalyzer()), new AuditLog($events, new MockClock('2026-11-01 10:00:00 UTC')));

        try {
            $audited->execute(new AuditContext(AuditActor::Web, $user, null, '203.0.113.7'), Uuid::v7(), $target, 'DELETE FROM t WHERE id = 1', new ScriptOptions(WritePermissions::all()), true);
            self::fail('The failure must be raised again.');
        } catch (\RuntimeException) {
        }
        $this->entityManager->clear();

        $found = $events->findForUser($user);
        self::assertCount(1, $found);
        self::assertSame('query.failed', $found[0]->getAction()->value);
        self::assertSame('executor_error', $found[0]->getDetails()['reason']);
        self::assertSame('DELETE FROM t WHERE id = 1', $found[0]->getDetails()['sql']);
        self::assertStringNotContainsString('4242', (string) json_encode($found[0]->getDetails()));
    }
}
