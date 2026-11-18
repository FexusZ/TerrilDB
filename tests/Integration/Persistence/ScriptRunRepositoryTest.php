<?php

declare(strict_types=1);

namespace App\Tests\Integration\Persistence;

use App\Crypto\KeyDerivation;
use App\Entity\ScriptRun;
use App\Entity\ScriptRunStatus;
use App\Entity\User;
use App\Repository\ScriptRunRepository;
use App\Repository\UserRepository;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Uid\Uuid;

#[Group('integration')]
final class ScriptRunRepositoryTest extends DatabaseTestCase
{
    private const TEXT_HASH = 'a3f1c0de9b2e4d5f6a7b8c9d0e1f2a3b4c5d6e7f8091a2b3c4d5e6f708192a3b';

    private ScriptRunRepository $repository;
    private UserRepository $users;

    protected function setUp(): void
    {
        parent::setUp();

        $repository = self::getContainer()->get(ScriptRunRepository::class);
        self::assertInstanceOf(ScriptRunRepository::class, $repository);
        $this->repository = $repository;
        $users = self::getContainer()->get(UserRepository::class);
        self::assertInstanceOf(UserRepository::class, $users);
        $this->users = $users;
    }

    private function user(string $name): User
    {
        $user = new User($name.'@example.test', $name, '$argon2id$v=19$m=16384,t=3,p=1$c29tZXNhbHQ$aGFzaGhhc2hoYXNoaGFzaA', (new KeyDerivation(opsLimit: 1, memLimit: 8192))->newParameters(), new \DateTimeImmutable('2026-11-01T10:00:00Z'));
        $this->users->save($user);

        return $user;
    }

    private function scriptRun(User $owner, string $createdAt = '2026-11-18T10:00:00Z', int $total = 3): ScriptRun
    {
        return new ScriptRun(Uuid::v7(), $owner, Uuid::v7(), self::TEXT_HASH, $total, false, new \DateTimeImmutable($createdAt));
    }

    public function testASavedRunComesBackWithEveryField(): void
    {
        $alice = $this->user('alice');
        $run = $this->scriptRun($alice);
        $run->start(new \DateTimeImmutable('2026-11-18T10:00:01Z'));
        $run->recordStatement(1, 'applied', 4, 7, null, new \DateTimeImmutable('2026-11-18T10:00:02Z'));
        $run->requestCancel();
        $this->repository->save($run);
        $this->entityManager->clear();

        $found = $this->repository->findOwned($run->getId(), $alice->getId());

        self::assertNotNull($found);
        self::assertSame(ScriptRunStatus::Running, $found->getStatus());
        self::assertSame(3, $found->getTotal());
        self::assertSame(1, $found->getCompleted());
        self::assertTrue($found->isCancelRequested());
        self::assertSame(self::TEXT_HASH, $found->getTextHash());
        self::assertSame([['rank' => 1, 'status' => 'applied', 'affectedRows' => 4, 'durationMs' => 7, 'sqlstate' => null]], $found->getStatements());
        self::assertEquals(new \DateTimeImmutable('2026-11-18T10:00:02Z'), $found->getHeartbeatAt());
    }

    public function testARunOfAnotherAccountIsIndistinguishableFromAMissingOne(): void
    {
        $alice = $this->user('alice');
        $bob = $this->user('bob');
        $run = $this->scriptRun($alice);
        $this->repository->save($run);
        $this->entityManager->clear();

        self::assertNull($this->repository->findOwned($run->getId(), $bob->getId()));
        self::assertNull($this->repository->findOwned(Uuid::v7(), $alice->getId()));
    }

    public function testStaleRunsAreInterruptedAndTheOthersLeftAlone(): void
    {
        $alice = $this->user('alice');
        $stale = $this->scriptRun($alice);
        $stale->start(new \DateTimeImmutable('2026-11-18T10:00:00Z'));
        $alive = $this->scriptRun($alice);
        $alive->start(new \DateTimeImmutable('2026-11-18T10:00:50Z'));
        $ended = $this->scriptRun($alice);
        $ended->start(new \DateTimeImmutable('2026-11-18T10:00:00Z'));
        $ended->finish(ScriptRunStatus::Committed, new \DateTimeImmutable('2026-11-18T10:00:01Z'));
        foreach ([$stale, $alive, $ended] as $run) {
            $this->repository->save($run);
        }
        $this->entityManager->clear();

        $count = $this->repository->interruptStale(new \DateTimeImmutable('2026-11-18T10:01:00Z'));
        $this->entityManager->clear();

        self::assertSame(1, $count);
        self::assertSame(ScriptRunStatus::Interrupted, $this->repository->findOwned($stale->getId(), $alice->getId())?->getStatus());
        self::assertSame(ScriptRunStatus::Running, $this->repository->findOwned($alive->getId(), $alice->getId())?->getStatus());
        self::assertSame(ScriptRunStatus::Committed, $this->repository->findOwned($ended->getId(), $alice->getId())?->getStatus());
        $interrupted = $this->repository->findOwned($stale->getId(), $alice->getId());
        self::assertEquals(new \DateTimeImmutable('2026-11-18T10:01:00Z'), $interrupted?->getFinishedAt());
    }

    public function testOnlyEndedRunsOlderThanTheLimitArePurged(): void
    {
        $alice = $this->user('alice');
        $oldEnded = $this->scriptRun($alice, '2025-01-01T10:00:00Z');
        $oldEnded->start(new \DateTimeImmutable('2025-01-01T10:00:00Z'));
        $oldEnded->finish(ScriptRunStatus::Failed, new \DateTimeImmutable('2025-01-01T10:00:01Z'));
        $recentEnded = $this->scriptRun($alice, '2026-11-17T10:00:00Z');
        $recentEnded->start(new \DateTimeImmutable('2026-11-17T10:00:00Z'));
        $recentEnded->finish(ScriptRunStatus::Committed, new \DateTimeImmutable('2026-11-17T10:00:01Z'));
        $oldRunning = $this->scriptRun($alice, '2025-01-01T10:00:00Z');
        $oldRunning->start(new \DateTimeImmutable('2025-01-01T10:00:00Z'));
        foreach ([$oldEnded, $recentEnded, $oldRunning] as $run) {
            $this->repository->save($run);
        }
        $this->entityManager->clear();

        $removed = $this->repository->purgeOlderThan(new \DateTimeImmutable('2026-01-01T00:00:00Z'));
        $this->entityManager->clear();

        self::assertSame(1, $removed);
        self::assertNull($this->repository->findOwned($oldEnded->getId(), $alice->getId()));
        self::assertNotNull($this->repository->findOwned($recentEnded->getId(), $alice->getId()));
        // Une exécution jamais terminée n'est pas effacée par la purge : elle est d'abord déclarée interrompue.
        self::assertNotNull($this->repository->findOwned($oldRunning->getId(), $alice->getId()));
    }

    public function testRunsGoWithTheirOwner(): void
    {
        $alice = $this->user('alice');
        $run = $this->scriptRun($alice);
        $this->repository->save($run);
        $this->entityManager->clear();

        $this->entityManager->getConnection()->executeStatement('DELETE FROM app_user WHERE id = ?', [$alice->getId()->toRfc4122()]);

        self::assertNull($this->repository->findOwned($run->getId(), $alice->getId()));
    }
}
