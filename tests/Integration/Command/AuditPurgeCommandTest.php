<?php

declare(strict_types=1);

namespace App\Tests\Integration\Command;

use App\Audit\AuditAction;
use App\Audit\AuditActor;
use App\Audit\AuditContext;
use App\Audit\AuditLog;
use App\Command\AuditPurgeCommand;
use App\Repository\AuditEventRepository;
use App\Tests\Integration\Persistence\DatabaseTestCase;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Uid\Uuid;

#[Group('integration')]
final class AuditPurgeCommandTest extends DatabaseTestCase
{
    private AuditEventRepository $events;
    private MockClock $clock;

    protected function setUp(): void
    {
        parent::setUp();

        $events = self::getContainer()->get(AuditEventRepository::class);
        self::assertInstanceOf(AuditEventRepository::class, $events);
        $this->events = $events;
        $this->clock = new MockClock('2026-01-01 10:00:00 UTC');
    }

    private function seed(Uuid $user, string $when): void
    {
        $clock = new MockClock($when);
        (new AuditLog($this->events, $clock))->record(new AuditContext(AuditActor::System, $user, null, null), AuditAction::LoginSuccess, details: ['when' => $when]);
    }

    private function tester(?int $defaultDays = null): CommandTester
    {
        return new CommandTester(new AuditPurgeCommand($this->events, $this->clock, $defaultDays ?? 365));
    }

    public function testRemovesWhatIsOlderThanTheGivenNumberOfDays(): void
    {
        $user = Uuid::v7();
        $this->seed($user, '2025-01-01 10:00:00 UTC');
        $this->seed($user, '2025-12-25 10:00:00 UTC');
        $this->entityManager->clear();

        $tester = $this->tester();
        $status = $tester->execute(['--days' => '30']);
        $this->entityManager->clear();

        self::assertSame(0, $status);
        self::assertStringContainsString('1', $tester->getDisplay());
        self::assertSame(['2025-12-25 10:00:00 UTC'], array_map(static fn ($e): mixed => $e->getDetails()['when'], $this->events->findForUser($user)));
    }

    public function testUsesTheConfiguredRetentionByDefault(): void
    {
        $user = Uuid::v7();
        $this->seed($user, '2024-06-01 10:00:00 UTC');
        $this->seed($user, '2025-12-25 10:00:00 UTC');

        $this->tester(defaultDays: 365)->execute([]);
        $this->entityManager->clear();

        self::assertCount(1, $this->events->findForUser($user));
    }

    public function testRefusesANonsensicalRetention(): void
    {
        foreach (['0', '-5', 'abc', '1.5'] as $days) {
            $tester = $this->tester();

            self::assertSame(2, $tester->execute(['--days' => $days]), $days);
            self::assertStringContainsString('jours', $tester->getDisplay());
        }
    }

    public function testNothingToPurgeIsNotAnError(): void
    {
        $tester = $this->tester();

        self::assertSame(0, $tester->execute(['--days' => '30']));
        self::assertStringContainsString('0', $tester->getDisplay());
    }
}
