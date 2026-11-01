<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Crypto\KeyDerivation;
use App\Entity\Exception\InvalidInvitationException;
use App\Entity\Invitation;
use App\Entity\Role;
use App\Entity\User;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

final class InvitationTest extends TestCase
{
    private const HASH = '$argon2id$v=19$m=16384,t=3,p=1$c29tZXNhbHQ$aGFzaGhhc2hoYXNoaGFzaA';
    private const VERIFIER = 'a3f1c0de9b2e4d5f6a7b8c9d0e1f2a3b4c5d6e7f8091a2b3c4d5e6f708192a3b';
    private const NOW = '2026-11-01T10:00:00+00:00';

    private function user(string $name = 'alice', Role $role = Role::Admin): User
    {
        return new User($name.'@example.test', $name, self::HASH, (new KeyDerivation(opsLimit: 1, memLimit: 8192))->newParameters(), new \DateTimeImmutable(self::NOW), $role);
    }

    private function invitation(string $note = 'Pour Marie', string $verifier = self::VERIFIER, string $expires = '2026-11-08T10:00:00+00:00'): Invitation
    {
        return new Invitation(Uuid::v7(), $this->user(), $note, $verifier, new \DateTimeImmutable(self::NOW), new \DateTimeImmutable($expires));
    }

    public function testKeepsWhatWasGiven(): void
    {
        $admin = $this->user();
        $id = Uuid::v7();

        $invitation = new Invitation($id, $admin, '  Pour Marie ', self::VERIFIER, new \DateTimeImmutable(self::NOW), new \DateTimeImmutable('2026-11-08T10:00:00+00:00'));

        self::assertSame($id, $invitation->getId());
        self::assertSame($admin, $invitation->getCreatedBy());
        self::assertSame('Pour Marie', $invitation->getNote());
        self::assertEquals(new \DateTimeImmutable('2026-11-08T10:00:00+00:00'), $invitation->getExpiresAt());
        self::assertNull($invitation->getUsedAt());
        self::assertNull($invitation->getUsedBy());
    }

    public function testTheNoteIsOptional(): void
    {
        self::assertSame('', $this->invitation(note: '')->getNote());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidNotes(): iterable
    {
        yield 'too long' => [str_repeat('a', 101)];
        yield 'control character' => ["Marie\nDupont"];
    }

    #[DataProvider('invalidNotes')]
    public function testRefusesAnInvalidNote(string $note): void
    {
        $this->expectException(InvalidInvitationException::class);

        $this->invitation(note: $note);
    }

    public function testOnlyKeepsAVerifierNotASecret(): void
    {
        $this->expectException(InvalidInvitationException::class);

        $this->invitation(verifier: 'inv_not-a-fingerprint');
    }

    public function testMustExpireAfterItsCreation(): void
    {
        $this->expectException(InvalidInvitationException::class);

        $this->invitation(expires: self::NOW);
    }

    public function testRecognisesItsVerifierOnly(): void
    {
        $invitation = $this->invitation();

        self::assertTrue($invitation->matches(self::VERIFIER));
        self::assertFalse($invitation->matches(str_repeat('0', 64)));
    }

    public function testExpiresAtTheGivenInstant(): void
    {
        $invitation = $this->invitation();

        self::assertFalse($invitation->isExpired(new \DateTimeImmutable('2026-11-08T09:59:59+00:00')));
        self::assertTrue($invitation->isExpired(new \DateTimeImmutable('2026-11-08T10:00:00+00:00')));
    }

    public function testIsUsedOnceByTheAccountItCreated(): void
    {
        $invitation = $this->invitation();
        $guest = $this->user('marie', Role::Member);
        $now = new \DateTimeImmutable('2026-11-02T10:00:00+00:00');

        self::assertTrue($invitation->isUsable($now));
        $invitation->markUsed($guest, $now);

        self::assertFalse($invitation->isUsable($now));
        self::assertSame($guest, $invitation->getUsedBy());
        self::assertEquals($now, $invitation->getUsedAt());
        $this->expectException(InvalidInvitationException::class);
        $invitation->markUsed($this->user('eve', Role::Member), $now);
    }

    public function testAnExpiredInvitationCannotBeUsed(): void
    {
        $invitation = $this->invitation();

        $this->expectException(InvalidInvitationException::class);

        $invitation->markUsed($this->user('marie', Role::Member), new \DateTimeImmutable('2026-11-09T10:00:00+00:00'));
    }

    public function testBelongsToItsAuthor(): void
    {
        $invitation = $this->invitation();

        self::assertTrue($invitation->wasCreatedBy($invitation->getCreatedBy()));
        self::assertFalse($invitation->wasCreatedBy($this->user('bob')));
    }
}
