<?php

declare(strict_types=1);

namespace App\Tests\Integration\Command;

use App\Audit\AuditAction;
use App\Audit\AuditActor;
use App\Audit\AuditContext;
use App\Audit\AuditLog;
use App\Command\AuditPurgeCommand;
use App\Crypto\KeyDerivation;
use App\Entity\ScriptRun;
use App\Entity\ScriptRunStatus;
use App\Entity\User;
use App\Repository\AuditEventRepository;
use App\Repository\ScriptRunRepository;
use App\Repository\UserRepository;
use App\Tests\Integration\Persistence\DatabaseTestCase;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Uid\Uuid;

#[Group('integration')]
final class AuditPurgeCommandTest extends DatabaseTestCase
{
    private AuditEventRepository $events;
    private ScriptRunRepository $runs;
    private MockClock $clock;

    protected function setUp(): void
    {
        parent::setUp();

        $events = self::getContainer()->get(AuditEventRepository::class);
        self::assertInstanceOf(AuditEventRepository::class, $events);
        $this->events = $events;
        $runs = self::getContainer()->get(ScriptRunRepository::class);
        self::assertInstanceOf(ScriptRunRepository::class, $runs);
        $this->runs = $runs;
        $this->clock = new MockClock('2026-01-01 10:00:00 UTC');
    }

    private function seed(Uuid $user, string $when): void
    {
        $clock = new MockClock($when);
        (new AuditLog($this->events, $clock))->record(new AuditContext(AuditActor::System, $user, null, null), AuditAction::LoginSuccess, details: ['when' => $when]);
    }

    private function tester(?int $defaultDays = null): CommandTester
    {
        return new CommandTester(new AuditPurgeCommand($this->events, $this->runs, $this->clock, $defaultDays ?? 365));
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

    public function testTheExecutionsOfScriptsFollowTheSameRetentionAndStaleOnesAreInterruptedFirst(): void
    {
        $users = self::getContainer()->get(UserRepository::class);
        self::assertInstanceOf(UserRepository::class, $users);
        $owner = new User('carol@example.test', 'carol', '$argon2id$v=19$m=16384,t=3,p=1$c29tZXNhbHQ$aGFzaGhhc2hoYXNoaGFzaA', (new KeyDerivation(opsLimit: 1, memLimit: 8192))->newParameters(), new \DateTimeImmutable('2025-01-01T10:00:00Z'));
        $users->save($owner);
        $hash = 'a3f1c0de9b2e4d5f6a7b8c9d0e1f2a3b4c5d6e7f8091a2b3c4d5e6f708192a3b';
        $old = new ScriptRun(Uuid::v7(), $owner, Uuid::v7(), $hash, 2, false, new \DateTimeImmutable('2025-01-01T10:00:00Z'));
        $old->start(new \DateTimeImmutable('2025-01-01T10:00:00Z'));
        $old->finish(ScriptRunStatus::Committed, new \DateTimeImmutable('2025-01-01T10:00:05Z'));
        $recent = new ScriptRun(Uuid::v7(), $owner, Uuid::v7(), $hash, 2, false, new \DateTimeImmutable('2025-12-25T10:00:00Z'));
        $recent->start(new \DateTimeImmutable('2025-12-25T10:00:00Z'));
        $stuck = new ScriptRun(Uuid::v7(), $owner, Uuid::v7(), $hash, 2, false, new \DateTimeImmutable('2025-12-31T09:00:00Z'));
        $stuck->start(new \DateTimeImmutable('2025-12-31T09:00:00Z'));
        foreach ([$old, $recent, $stuck] as $run) {
            $this->runs->save($run);
        }
        $this->entityManager->clear();

        $tester = $this->tester();
        $tester->execute(['--days' => '30']);
        $this->entityManager->clear();

        self::assertNull($this->runs->findOwned($old->getId(), $owner->getId()));
        // Trop vieille d'un jour de conservation mais jamais terminée : interrompue (plus de signe de vie), conservée.
        self::assertSame(ScriptRunStatus::Interrupted, $this->runs->findOwned($stuck->getId(), $owner->getId())?->getStatus());
        self::assertSame(ScriptRunStatus::Interrupted, $this->runs->findOwned($recent->getId(), $owner->getId())?->getStatus());
        self::assertStringContainsString('exécution', $tester->getDisplay());
    }
}
