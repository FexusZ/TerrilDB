<?php

declare(strict_types=1);

namespace App\Tests\Integration\Confirmation;

use App\Confirmation\ConfirmationBinding;
use App\Confirmation\ConfirmationKind;
use App\Confirmation\ConfirmationTickets;
use App\Confirmation\Exception\InvalidConfirmationException;
use App\Crypto\KeyDerivation;
use App\Entity\ApiToken;
use App\Entity\User;
use App\Repository\ApiTokenRepository;
use App\Repository\ConfirmationTicketRepository;
use App\Repository\UserRepository;
use App\Tests\Integration\Persistence\DatabaseTestCase;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Uid\Uuid;

#[Group('integration')]
final class ConfirmationTicketsTest extends DatabaseTestCase
{
    private const VERIFIER = 'a3f1c0de9b2e4d5f6a7b8c9d0e1f2a3b4c5d6e7f8091a2b3c4d5e6f708192a3b';

    private ConfirmationTickets $tickets;
    private MockClock $clock;
    private ApiToken $token;
    private Uuid $connection;

    protected function setUp(): void
    {
        parent::setUp();
        $this->clock = new MockClock('2026-11-12 10:00:00');
        $repository = self::getContainer()->get(ConfirmationTicketRepository::class);
        self::assertInstanceOf(ConfirmationTicketRepository::class, $repository);
        $this->tickets = new ConfirmationTickets($repository, $this->clock);

        $users = self::getContainer()->get(UserRepository::class);
        self::assertInstanceOf(UserRepository::class, $users);
        $owner = new User('a@example.test', 'alice', '$argon2id$v=19$m=16384,t=3,p=1$c29tZXNhbHQ$aGFzaGhhc2hoYXNoaGFzaA', (new KeyDerivation(opsLimit: 1, memLimit: 8192))->newParameters(), new \DateTimeImmutable('2026-11-01T10:00:00Z'));
        $users->save($owner);
        $this->connection = Uuid::v7();
        $this->token = $this->newToken($owner);
    }

    private function newToken(User $owner): ApiToken
    {
        $repository = self::getContainer()->get(ApiTokenRepository::class);
        self::assertInstanceOf(ApiTokenRepository::class, $repository);
        $token = new ApiToken(Uuid::v7(), $owner, 'CI', self::VERIFIER, 'v1:AAAAwrapped', [$this->connection->toRfc4122()], new \DateTimeImmutable('2026-11-01T10:00:00Z'), new \DateTimeImmutable('2026-12-01T10:00:00Z'));
        $repository->save($token);

        return $token;
    }

    private function binding(string $text = 'UPDATE t SET a = 1 WHERE id = 2', string $decision = 'allowed', ?ApiToken $token = null, ?Uuid $connection = null, ConfirmationKind $kind = ConfirmationKind::Statement): ConfirmationBinding
    {
        return new ConfirmationBinding(($token ?? $this->token)->getId(), $connection ?? $this->connection, $kind, $text, $decision);
    }

    public function testAnIssuedTicketIsRedeemedOnceForTheExactSameStatement(): void
    {
        $issued = $this->tickets->issue($this->token, $this->binding());

        self::assertStringStartsWith('cft_', $issued->token);
        self::assertSame(300, $issued->expiresAt->getTimestamp() - $this->clock->now()->getTimestamp());
        $this->tickets->redeem($issued->token, $this->binding());
        $this->addToAssertionCount(1);

        $this->expectException(InvalidConfirmationException::class);
        $this->tickets->redeem($issued->token, $this->binding());
    }

    public function testAReplayIsRefused(): void
    {
        $issued = $this->tickets->issue($this->token, $this->binding());
        $this->tickets->redeem($issued->token, $this->binding());

        $this->expectException(InvalidConfirmationException::class);
        $this->tickets->redeem($issued->token, $this->binding());
    }

    public function testAStatementDifferingByOneByteIsRefusedAndBurnsTheTicket(): void
    {
        $issued = $this->tickets->issue($this->token, $this->binding());

        try {
            $this->tickets->redeem($issued->token, $this->binding('UPDATE t SET a = 1 WHERE id = 2 '));
            self::fail('A changed statement must be refused.');
        } catch (InvalidConfirmationException) {
        }

        $this->expectException(InvalidConfirmationException::class);
        $this->tickets->redeem($issued->token, $this->binding());
    }

    public function testAnExpiredTicketIsRefused(): void
    {
        $issued = $this->tickets->issue($this->token, $this->binding());
        $this->clock->sleep(301);

        $this->expectException(InvalidConfirmationException::class);
        $this->tickets->redeem($issued->token, $this->binding());
    }

    public function testATicketStillWorksJustBeforeItsExpiry(): void
    {
        $issued = $this->tickets->issue($this->token, $this->binding());
        $this->clock->sleep(299);

        $this->tickets->redeem($issued->token, $this->binding());
        $this->addToAssertionCount(1);
    }

    public function testATicketIsBoundToItsConnection(): void
    {
        $issued = $this->tickets->issue($this->token, $this->binding());

        $this->expectException(InvalidConfirmationException::class);
        $this->tickets->redeem($issued->token, $this->binding(connection: Uuid::v7()));
    }

    public function testATicketIsBoundToItsApiToken(): void
    {
        $issued = $this->tickets->issue($this->token, $this->binding());
        $other = $this->newToken($this->token->getOwner());

        $this->expectException(InvalidConfirmationException::class);
        $this->tickets->redeem($issued->token, $this->binding(token: $other));
    }

    public function testATicketIsBoundToTheVerdictItWasIssuedFor(): void
    {
        $issued = $this->tickets->issue($this->token, $this->binding(decision: 'needs_confirmation'));

        $this->expectException(InvalidConfirmationException::class);
        $this->tickets->redeem($issued->token, $this->binding(decision: 'allowed'));
    }

    public function testAStatementTicketCannotConfirmAScript(): void
    {
        $issued = $this->tickets->issue($this->token, $this->binding());

        $this->expectException(InvalidConfirmationException::class);
        $this->tickets->redeem($issued->token, $this->binding(kind: ConfirmationKind::Script));
    }

    public function testGarbageIsRefused(): void
    {
        $this->expectException(InvalidConfirmationException::class);

        $this->tickets->redeem('cft_nothing', $this->binding());
    }

    public function testTheTicketTextIsNeverStoredInClear(): void
    {
        $issued = $this->tickets->issue($this->token, $this->binding('UPDATE secrets SET v = 1 WHERE id = 2'));

        $rows = $this->entityManager->getConnection()->fetchAllAssociative('SELECT * FROM confirmation_ticket');

        self::assertCount(1, $rows);
        self::assertStringNotContainsString($issued->token, json_encode($rows, \JSON_THROW_ON_ERROR));
        self::assertStringNotContainsString('secrets', json_encode($rows, \JSON_THROW_ON_ERROR));
    }

    public function testRevokingTheTokenDeletesItsTickets(): void
    {
        $this->tickets->issue($this->token, $this->binding());
        $repository = self::getContainer()->get(ApiTokenRepository::class);
        self::assertInstanceOf(ApiTokenRepository::class, $repository);

        $repository->remove($this->token);

        self::assertSame(0, $this->tickets());
    }

    public function testIssuingCleansTheExpiredTicketsOfTheToken(): void
    {
        $this->tickets->issue($this->token, $this->binding('SELECT 1'));
        $this->clock->sleep(400);

        $this->tickets->issue($this->token, $this->binding('SELECT 2'));

        self::assertSame(1, $this->tickets());
    }

    private function tickets(): int
    {
        $count = $this->entityManager->getConnection()->fetchOne('SELECT count(*) FROM confirmation_ticket');

        return is_numeric($count) ? (int) $count : -1;
    }
}
