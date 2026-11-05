<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Crypto\KeyDerivation;
use App\Entity\ApiToken;
use App\Entity\Exception\InvalidApiTokenDefinitionException;
use App\Entity\User;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

final class ApiTokenTest extends TestCase
{
    private const HASH = '$argon2id$v=19$m=16384,t=3,p=1$c29tZXNhbHQ$aGFzaGhhc2hoYXNoaGFzaA';
    private const VERIFIER = 'a3f1c0de9b2e4d5f6a7b8c9d0e1f2a3b4c5d6e7f8091a2b3c4d5e6f708192a3b';
    private const NOW = '2026-11-01T10:00:00+00:00';

    private function user(string $name = 'alice'): User
    {
        return new User($name.'@example.test', $name, self::HASH, (new KeyDerivation(opsLimit: 1, memLimit: 8192))->newParameters(), new \DateTimeImmutable(self::NOW));
    }

    /**
     * @param list<string> $connections
     */
    private function token(array $connections = [], ?User $owner = null, string $expires = '2026-12-01T10:00:00+00:00'): ApiToken
    {
        return new ApiToken(Uuid::v7(), $owner ?? $this->user(), 'CI', self::VERIFIER, 'v1:AAAAwrapped', $connections, new \DateTimeImmutable(self::NOW), new \DateTimeImmutable($expires));
    }

    public function testKeepsWhatWasGiven(): void
    {
        $owner = $this->user();
        $other = Uuid::v7()->toRfc4122();
        $id = Uuid::v7();

        $token = new ApiToken($id, $owner, ' Assistant ', self::VERIFIER, 'v1:AAAAwrapped', [$other], new \DateTimeImmutable(self::NOW), new \DateTimeImmutable('2026-12-01T10:00:00+00:00'));

        self::assertTrue($token->getId()->equals($id));
        self::assertSame($owner, $token->getOwner());
        self::assertSame('Assistant', $token->getName());
        self::assertSame('v1:AAAAwrapped', $token->getWrappedKey());
        self::assertSame([$other], $token->getConnectionIds());
        self::assertSame('2026-11-01T10:00:00+00:00', $token->getCreatedAt()->format(\DATE_ATOM));
        self::assertSame('2026-12-01T10:00:00+00:00', $token->getExpiresAt()->format(\DATE_ATOM));
        self::assertNull($token->getLastUsedAt());
        self::assertTrue($token->belongsTo($owner));
        self::assertFalse($token->belongsTo($this->user('bob')));
    }

    public function testDatesAreKeptInUtc(): void
    {
        $token = new ApiToken(Uuid::v7(), $this->user(), 'CI', self::VERIFIER, 'v1:AAAAwrapped', [], new \DateTimeImmutable('2026-11-01T12:00:00+02:00'), new \DateTimeImmutable('2026-12-01T12:00:00+02:00'));

        self::assertSame('2026-11-01T10:00:00+00:00', $token->getCreatedAt()->format(\DATE_ATOM));
        self::assertSame('2026-12-01T10:00:00+00:00', $token->getExpiresAt()->format(\DATE_ATOM));
    }

    public function testExpiresAtItsExpiryInstant(): void
    {
        $token = $this->token();

        self::assertFalse($token->isExpired(new \DateTimeImmutable('2026-12-01T09:59:59+00:00')));
        self::assertTrue($token->isExpired(new \DateTimeImmutable('2026-12-01T10:00:00+00:00')));
        self::assertTrue($token->isExpired(new \DateTimeImmutable('2027-01-01T00:00:00+00:00')));
    }

    public function testOnlyGrantsTheConnectionsItWasGiven(): void
    {
        $granted = Uuid::v7();
        $token = $this->token([$granted->toRfc4122()]);

        self::assertTrue($token->allows($granted));
        self::assertFalse($token->allows(Uuid::v7()));
        self::assertFalse($this->token([])->allows($granted), 'sans connexion listée, rien n’est permis');
    }

    public function testRecognizesItsVerifierInConstantTime(): void
    {
        $token = $this->token();

        self::assertTrue($token->matches(self::VERIFIER));
        self::assertFalse($token->matches(str_repeat('0', 64)));
        self::assertFalse($token->matches(''));
    }

    private static function lastUse(ApiToken $token): ?string
    {
        return $token->getLastUsedAt()?->format(\DATE_ATOM);
    }

    public function testAFixedListTokenIsNotMarkedAsCoveringAllConnections(): void
    {
        self::assertFalse($this->token()->coversAllConnections());
    }

    public function testATokenForAllConnectionsCanRefreshItsList(): void
    {
        $first = Uuid::v7();
        $second = Uuid::v7();
        $token = new ApiToken(Uuid::v7(), $this->user(), 'CI', self::VERIFIER, 'v1:AAAAwrapped', [$first->toRfc4122()], new \DateTimeImmutable(self::NOW), new \DateTimeImmutable('2026-12-01T10:00:00+00:00'), allConnections: true);

        $token->refreshConnections([$first->toRfc4122(), $second->toRfc4122()]);

        self::assertTrue($token->coversAllConnections());
        self::assertSame([$first->toRfc4122(), $second->toRfc4122()], $token->getConnectionIds());
        self::assertTrue($token->allows($second));
    }

    public function testRefreshDropsConnectionsThatNoLongerExist(): void
    {
        $gone = Uuid::v7();
        $kept = Uuid::v7();
        $token = new ApiToken(Uuid::v7(), $this->user(), 'CI', self::VERIFIER, 'v1:AAAAwrapped', [$gone->toRfc4122(), $kept->toRfc4122()], new \DateTimeImmutable(self::NOW), new \DateTimeImmutable('2026-12-01T10:00:00+00:00'), allConnections: true);

        $token->refreshConnections([$kept->toRfc4122()]);

        self::assertFalse($token->allows($gone));
        self::assertTrue($token->allows($kept));
    }

    public function testAFixedListTokenCannotBeRefreshed(): void
    {
        $this->expectException(InvalidApiTokenDefinitionException::class);

        $this->token([Uuid::v7()->toRfc4122()])->refreshConnections([Uuid::v7()->toRfc4122()]);
    }

    public function testRefreshStillRefusesTooManyConnections(): void
    {
        $token = new ApiToken(Uuid::v7(), $this->user(), 'CI', self::VERIFIER, 'v1:AAAAwrapped', [Uuid::v7()->toRfc4122()], new \DateTimeImmutable(self::NOW), new \DateTimeImmutable('2026-12-01T10:00:00+00:00'), allConnections: true);

        $this->expectException(InvalidApiTokenDefinitionException::class);

        $token->refreshConnections(array_map(static fn (): string => Uuid::v7()->toRfc4122(), range(1, 51)));
    }

    public function testTheLastUseIsRecordedAtMostOncePerMinute(): void
    {
        $token = $this->token();

        self::assertTrue($token->recordUse(new \DateTimeImmutable('2026-11-02T10:00:00+00:00')));
        self::assertFalse($token->recordUse(new \DateTimeImmutable('2026-11-02T10:00:30+00:00')));
        self::assertSame('2026-11-02T10:00:00+00:00', self::lastUse($token));
        self::assertTrue($token->recordUse(new \DateTimeImmutable('2026-11-02T10:01:00+00:00')));
        self::assertSame('2026-11-02T10:01:00+00:00', self::lastUse($token));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidNames(): iterable
    {
        yield 'empty' => [''];
        yield 'blank' => ["  \t "];
        yield 'too long' => [str_repeat('é', 101)];
        yield 'control character' => ["CI\x00job"];
    }

    #[DataProvider('invalidNames')]
    public function testInvalidNamesAreRefused(string $name): void
    {
        $this->expectException(InvalidApiTokenDefinitionException::class);

        new ApiToken(Uuid::v7(), $this->user(), $name, self::VERIFIER, 'v1:AAAAwrapped', [], new \DateTimeImmutable(self::NOW), new \DateTimeImmutable('2026-12-01T10:00:00+00:00'));
    }

    public function testRefusesAWrappedKeyThatIsNotEncrypted(): void
    {
        $this->expectException(InvalidApiTokenDefinitionException::class);

        new ApiToken(Uuid::v7(), $this->user(), 'CI', self::VERIFIER, 'raw-key-bytes', [], new \DateTimeImmutable(self::NOW), new \DateTimeImmutable('2026-12-01T10:00:00+00:00'));
    }

    public function testRefusesAMalformedVerifier(): void
    {
        $this->expectException(InvalidApiTokenDefinitionException::class);

        new ApiToken(Uuid::v7(), $this->user(), 'CI', 'not-a-sha256', 'v1:AAAAwrapped', [], new \DateTimeImmutable(self::NOW), new \DateTimeImmutable('2026-12-01T10:00:00+00:00'));
    }

    public function testRefusesAnExpiryThatIsNotAfterTheCreation(): void
    {
        $this->expectException(InvalidApiTokenDefinitionException::class);

        $this->token([], null, '2026-11-01T10:00:00+00:00');
    }

    public function testRefusesBadConnectionIdentifiers(): void
    {
        foreach ([['not-a-uuid'], [str_repeat('a', 36)], [Uuid::v7()->toRfc4122(), 5]] as $ids) {
            try {
                /* @phpstan-ignore argument.type */
                $this->token($ids);
                self::fail('Expected a refusal.');
            } catch (InvalidApiTokenDefinitionException) {
            }
        }
        $this->addToAssertionCount(1);
    }

    public function testDeduplicatesConnectionsAndBoundsTheirNumber(): void
    {
        $id = Uuid::v7()->toRfc4122();
        self::assertSame([$id], $this->token([$id, $id])->getConnectionIds());

        $this->expectException(InvalidApiTokenDefinitionException::class);
        $this->token(array_map(static fn (): string => Uuid::v7()->toRfc4122(), range(1, 51)));
    }
}
