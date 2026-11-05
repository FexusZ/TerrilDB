<?php

declare(strict_types=1);

namespace App\Tests\Audit;

use App\Audit\AuditAction;
use App\Audit\AuditActor;
use App\Audit\AuditOutcome;
use App\Audit\Exception\InvalidAuditEventException;
use App\Entity\AuditEvent;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

final class AuditEventTest extends TestCase
{
    /**
     * @param array<string, mixed> $details
     */
    private function event(array $details = [], ?string $ip = '203.0.113.7', ?\DateTimeImmutable $at = null): AuditEvent
    {
        return new AuditEvent(
            Uuid::v7(),
            $at ?? new \DateTimeImmutable('2026-11-01T10:00:00+00:00'),
            Uuid::v7(),
            AuditActor::Web,
            null,
            $ip,
            AuditAction::LoginSuccess,
            AuditOutcome::Success,
            null,
            $details,
        );
    }

    public function testKeepsWhatWasRecorded(): void
    {
        $user = Uuid::v7();
        $token = Uuid::v7();
        $subject = Uuid::v7();

        $event = new AuditEvent(Uuid::v7(), new \DateTimeImmutable('2026-11-01T12:00:00+02:00'), $user, AuditActor::Api, $token, '198.51.100.2', AuditAction::QueryExecuted, AuditOutcome::Refused, $subject, ['reason' => 'not_read_only', 'durationMs' => 12, 'truncated' => false, 'note' => null]);

        self::assertTrue($user->equals($event->getUserId()));
        self::assertSame(AuditActor::Api, $event->getActor());
        self::assertTrue($token->equals($event->getTokenId()));
        self::assertSame('198.51.100.2', $event->getIp());
        self::assertSame(AuditAction::QueryExecuted, $event->getAction());
        self::assertSame(AuditOutcome::Refused, $event->getOutcome());
        self::assertTrue($subject->equals($event->getSubjectId()));
        self::assertSame('2026-11-01T10:00:00+00:00', $event->getOccurredAt()->format(\DATE_ATOM));
        self::assertSame(['reason' => 'not_read_only', 'durationMs' => 12, 'truncated' => false, 'note' => null], $event->getDetails());
    }

    public function testAnAnonymousEventHasNoUser(): void
    {
        $event = new AuditEvent(Uuid::v7(), new \DateTimeImmutable(), null, AuditActor::Web, null, null, AuditAction::LoginFailure, AuditOutcome::Failed, null, []);

        self::assertNull($event->getUserId());
        self::assertNull($event->getIp());
    }

    public function testActionsAreNamedByCategoryAndVerb(): void
    {
        self::assertSame('login.success', AuditAction::LoginSuccess->value);
        self::assertSame('query.executed', AuditAction::QueryExecuted->value);
        self::assertSame('connection.deleted', AuditAction::ConnectionDeleted->value);
        self::assertSame('login', AuditAction::LoginSuccess->category());
        self::assertSame('query', AuditAction::QueryRefused->category());
        self::assertSame('target.forbidden', AuditAction::TargetForbidden->value);
        self::assertSame('target', AuditAction::TargetForbidden->category());
        foreach (AuditAction::cases() as $action) {
            self::assertMatchesRegularExpression('/^[a-z]+\.[a-z_]+$/D', $action->value);
        }
    }

    public function testAnOverlongAddressIsCut(): void
    {
        self::assertSame(45, \strlen($this->event([], str_repeat('1', 100))->getIp() ?? ''));
    }

    public function testLongTextDetailsAreCut(): void
    {
        $details = $this->event(['sql' => str_repeat('x', 20000)])->getDetails();

        self::assertIsString($details['sql']);
        self::assertSame(10000, mb_strlen($details['sql']));
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function refusedDetails(): iterable
    {
        yield 'a password' => [['password' => 'x']];
        yield 'a secret' => [['secret' => 'x']];
        yield 'a token' => [['token' => 'tdb_x']];
        yield 'a code' => [['code' => '123456']];
        yield 'a key' => [['key' => 'x']];
        yield 'a key in capitals' => [['Password' => 'x']];
        yield 'a badly named key' => [['bad key' => 'x']];
        yield 'a nested value' => [['rows' => [['a' => 1]]]];
        yield 'an object' => [['when' => new \DateTimeImmutable()]];
        yield 'too many details' => [array_combine(array_map(static fn (int $i): string => 'k'.$i, range(1, 21)), range(1, 21))];
    }

    /**
     * @param array<string, mixed> $details
     */
    #[DataProvider('refusedDetails')]
    public function testSecretLookingOrOversizedDetailsAreRefused(array $details): void
    {
        $this->expectException(InvalidAuditEventException::class);

        $this->event($details);
    }

    public function testAListOfStringsIsAllowed(): void
    {
        self::assertSame(['tables' => ['a', 'b']], $this->event(['tables' => ['a', 'b']])->getDetails());
    }
}
