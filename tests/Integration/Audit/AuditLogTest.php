<?php

declare(strict_types=1);

namespace App\Tests\Integration\Audit;

use App\Audit\AuditAction;
use App\Audit\AuditActor;
use App\Audit\AuditContext;
use App\Audit\AuditLog;
use App\Audit\AuditOutcome;
use App\Entity\AuditEvent;
use App\Repository\AuditEventRepository;
use App\Tests\Integration\Persistence\DatabaseTestCase;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Uid\Uuid;

#[Group('integration')]
final class AuditLogTest extends DatabaseTestCase
{
    private AuditEventRepository $events;
    private MockClock $clock;
    private AuditLog $log;

    protected function setUp(): void
    {
        parent::setUp();

        $events = self::getContainer()->get(AuditEventRepository::class);
        self::assertInstanceOf(AuditEventRepository::class, $events);
        $this->events = $events;
        $this->clock = new MockClock('2026-11-01 10:00:00 UTC');
        $this->log = new AuditLog($this->events, $this->clock);
    }

    private function web(?Uuid $user = null): AuditContext
    {
        return new AuditContext(AuditActor::Web, $user ?? Uuid::v7(), null, '203.0.113.7');
    }

    public function testRecordsAnEventWithEverythingItWasGiven(): void
    {
        $user = Uuid::v7();
        $subject = Uuid::v7();

        $this->log->record($this->web($user), AuditAction::ConnectionCreated, AuditOutcome::Success, $subject, ['engine' => 'postgresql']);
        $this->entityManager->clear();

        $found = $this->events->findForUser($user);
        self::assertCount(1, $found);
        $event = $found[0];
        self::assertSame(AuditAction::ConnectionCreated, $event->getAction());
        self::assertSame(AuditOutcome::Success, $event->getOutcome());
        self::assertSame(AuditActor::Web, $event->getActor());
        self::assertTrue($subject->equals($event->getSubjectId()));
        self::assertSame(['engine' => 'postgresql'], $event->getDetails());
        self::assertSame('203.0.113.7', $event->getIp());
        self::assertSame('2026-11-01T10:00:00+00:00', $event->getOccurredAt()->format(\DATE_ATOM));
    }

    public function testRecordsTheTokenThatActed(): void
    {
        $user = Uuid::v7();
        $token = Uuid::v7();

        $this->log->record(new AuditContext(AuditActor::Mcp, $user, $token, null), AuditAction::QueryExecuted);
        $this->entityManager->clear();

        $event = $this->events->findForUser($user)[0];
        self::assertSame(AuditActor::Mcp, $event->getActor());
        self::assertTrue($token->equals($event->getTokenId()));
    }

    public function testListsAUsersEventsNewestFirstAndNoOneElses(): void
    {
        $alice = Uuid::v7();
        $this->log->record($this->web($alice), AuditAction::LoginSuccess);
        $this->clock->sleep(60);
        $this->log->record($this->web($alice), AuditAction::PasswordChanged);
        $this->log->record($this->web(Uuid::v7()), AuditAction::LoginSuccess);
        $this->entityManager->clear();

        $actions = array_map(static fn (AuditEvent $e): string => $e->getAction()->value, $this->events->findForUser($alice));

        self::assertSame(['password.changed', 'login.success'], $actions);
    }

    public function testPagesThroughTheEventsWithACursor(): void
    {
        $alice = Uuid::v7();
        for ($i = 0; $i < 5; ++$i) {
            $this->log->record($this->web($alice), AuditAction::LoginSuccess, details: ['n' => $i]);
            $this->clock->sleep(1);
        }
        $this->entityManager->clear();

        $first = $this->events->findForUser($alice, limit: 2);
        $second = $this->events->findForUser($alice, before: $first[1]->getId(), limit: 2);
        $third = $this->events->findForUser($alice, before: $second[1]->getId(), limit: 2);

        self::assertSame([4, 3], array_map(static fn (AuditEvent $e): mixed => $e->getDetails()['n'], $first));
        self::assertSame([2, 1], array_map(static fn (AuditEvent $e): mixed => $e->getDetails()['n'], $second));
        self::assertSame([0], array_map(static fn (AuditEvent $e): mixed => $e->getDetails()['n'], $third));
    }

    public function testFiltersByCategory(): void
    {
        $alice = Uuid::v7();
        $this->log->record($this->web($alice), AuditAction::LoginSuccess);
        $this->log->record($this->web($alice), AuditAction::QueryExecuted);
        $this->log->record($this->web($alice), AuditAction::QueryRefused, AuditOutcome::Refused);
        $this->entityManager->clear();

        $queries = $this->events->findForUser($alice, category: 'query');

        self::assertCount(2, $queries);
        self::assertSame([], $this->events->findForUser($alice, category: 'nothing'));
    }

    public function testFiltersBySubject(): void
    {
        $alice = Uuid::v7();
        $mine = Uuid::v7();
        $this->log->record($this->web($alice), AuditAction::QueryExecuted, subject: $mine);
        $this->log->record($this->web($alice), AuditAction::QueryExecuted, subject: Uuid::v7());
        $this->entityManager->clear();

        self::assertCount(1, $this->events->findForUser($alice, subject: $mine));
    }

    public function testNeverRecordsASecret(): void
    {
        $this->expectException(\App\Audit\Exception\InvalidAuditEventException::class);

        $this->log->record($this->web(), AuditAction::LoginFailure, details: ['password' => 'hunter2']);
    }

    public function testAFailureToWriteNeverBreaksTheAction(): void
    {
        // Une transaction PostgreSQL avortée refuse toute écriture : l'audit ne doit pas faire échouer l'action en cours.
        try {
            $this->entityManager->getConnection()->executeStatement('SELECT * FROM table_that_does_not_exist');
        } catch (\Throwable) {
        }

        $this->log->record($this->web(), AuditAction::LoginSuccess);

        $this->addToAssertionCount(1);
    }

    public function testPurgesOnlyWhatIsOlderThanTheLimit(): void
    {
        $alice = Uuid::v7();
        $this->log->record($this->web($alice), AuditAction::LoginSuccess, details: ['n' => 'old']);
        $this->clock->sleep(86400 * 10);
        $this->log->record($this->web($alice), AuditAction::LoginSuccess, details: ['n' => 'recent']);
        $this->entityManager->clear();

        $removed = $this->events->purgeOlderThan(new \DateTimeImmutable('2026-11-05 00:00:00 UTC'));

        self::assertSame(1, $removed);
        self::assertSame(['recent'], array_map(static fn (AuditEvent $e): mixed => $e->getDetails()['n'], $this->events->findForUser($alice)));
    }
}
