<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Crypto\KdfParameters;
use App\Crypto\KeyDerivation;
use App\Entity\Exception\InvalidUserException;
use App\Entity\Role;
use App\Entity\User;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\UuidV7;

final class UserTest extends TestCase
{
    private const HASH = '$argon2id$v=19$m=16384,t=3,p=1$c29tZXNhbHQ$aGFzaGhhc2hoYXNoaGFzaA';

    private function kdf(): KdfParameters
    {
        return (new KeyDerivation(opsLimit: 1, memLimit: 8192))->newParameters();
    }

    private function user(string $email = 'alice@example.test', string $username = 'alice'): User
    {
        return new User($email, $username, self::HASH, $this->kdf(), new \DateTimeImmutable('2026-10-29T12:00:00Z'));
    }

    private function userWithTotp(): User
    {
        $user = $this->user();
        $user->enableTotp('v1:encrypted-secret', ['hash-1', 'hash-2']);

        return $user;
    }

    public function testAnAccountIsAMemberUnlessMadeAnAdministrator(): void
    {
        $member = $this->user();
        $admin = new User('root@example.test', 'root', self::HASH, $this->kdf(), new \DateTimeImmutable('2026-10-29T12:00:00Z'), Role::Admin);

        self::assertSame(Role::Member, $member->getRole());
        self::assertFalse($member->isAdmin());
        self::assertSame(Role::Admin, $admin->getRole());
        self::assertTrue($admin->isAdmin());
    }

    public function testAnAccountIsActiveUntilDisabledAndCanBeEnabledAgain(): void
    {
        $user = $this->user();
        self::assertFalse($user->isDisabled());
        self::assertNull($user->getDisabledAt());

        $user->disable(new \DateTimeImmutable('2026-11-12T10:00:00+01:00'));

        self::assertTrue($user->isDisabled());
        self::assertSame('2026-11-12T09:00:00+00:00', $user->getDisabledAt()?->format(\DATE_ATOM));

        $user->enable();

        self::assertFalse($user->isDisabled());
        self::assertNull($user->getDisabledAt());
    }

    public function testDisablingTwiceKeepsTheFirstDate(): void
    {
        $user = $this->user();
        $user->disable(new \DateTimeImmutable('2026-11-12T09:00:00Z'));
        $user->disable(new \DateTimeImmutable('2026-11-13T09:00:00Z'));

        self::assertSame('2026-11-12T09:00:00+00:00', $user->getDisabledAt()?->format(\DATE_ATOM));
    }

    public function testTheLastLoginIsRecordedInUtc(): void
    {
        $user = $this->user();
        self::assertNull($user->getLastLoginAt());

        $user->recordLogin(new \DateTimeImmutable('2026-11-12T10:00:00+01:00'));

        self::assertSame('2026-11-12T09:00:00+00:00', $user->getLastLoginAt()?->format(\DATE_ATOM));
    }

    public function testEmailAndUsernameAreNormalizedToLowercase(): void
    {
        $user = $this->user('  Alice@Example.TEST ', 'Alice_01');

        self::assertSame('alice@example.test', $user->getEmail());
        self::assertSame('alice_01', $user->getUsername());
    }

    public function testIdentityIsAFreshTimeOrderedUuid(): void
    {
        $first = $this->user();
        $second = $this->user();

        self::assertInstanceOf(UuidV7::class, $first->getId());
        self::assertFalse($first->getId()->equals($second->getId()));
        self::assertEquals(new \DateTimeImmutable('2026-10-29T12:00:00Z'), $first->getCreatedAt());
    }

    public function testCreationDateIsKeptInUtcWhateverTheTimezoneItWasGivenIn(): void
    {
        $user = new User(
            'alice@example.test',
            'alice',
            self::HASH,
            $this->kdf(),
            new \DateTimeImmutable('2026-10-29T14:00:00+02:00'),
        );

        self::assertSame('UTC', $user->getCreatedAt()->getTimezone()->getName());
        self::assertSame('2026-10-29T12:00:00+00:00', $user->getCreatedAt()->format(\DATE_ATOM));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidEmails(): iterable
    {
        yield 'vide' => [''];
        yield 'sans arobase' => ['alice.example.test'];
        yield 'sans domaine' => ['alice@'];
        yield 'sans point dans le domaine' => ['alice@example'];
        yield 'espace' => ['ali ce@example.test'];
        yield 'deux arobases' => ['alice@@example.test'];
        yield 'trop long' => [str_repeat('a', 250).'@example.test'];
    }

    #[DataProvider('invalidEmails')]
    public function testRejectsInvalidEmails(string $email): void
    {
        $this->expectException(InvalidUserException::class);

        $this->user($email);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidUsernames(): iterable
    {
        yield 'trop court' => ['ab'];
        yield 'trop long' => [str_repeat('a', 33)];
        yield 'espace' => ['ali ce'];
        yield 'arobase (confondu avec un e-mail)' => ['alice@home'];
        yield 'tiret en tête' => ['-alice'];
        yield 'point en tête' => ['.alice'];
        yield 'accents' => ['aliçe'];
        yield 'vide' => [''];
    }

    #[DataProvider('invalidUsernames')]
    public function testRejectsInvalidUsernames(string $username): void
    {
        $this->expectException(InvalidUserException::class);

        $this->user('alice@example.test', $username);
    }

    public function testRejectsAnythingButAnArgon2idHash(): void
    {
        $this->expectException(InvalidUserException::class);

        new User('alice@example.test', 'alice', 'plain-text-password', $this->kdf(), new \DateTimeImmutable());
    }

    public function testKdfParametersRoundTripThroughTheirStoredForm(): void
    {
        $kdf = $this->kdf();
        $user = new User('alice@example.test', 'alice', self::HASH, $kdf, new \DateTimeImmutable());

        self::assertSame($kdf->toString(), $user->getKdfParameters()->toString());
    }

    public function testTotpIsDisabledByDefault(): void
    {
        $user = $this->user();

        self::assertFalse($user->isTotpEnabled());
        self::assertNull($user->getTotpSecret());
        self::assertSame(0, $user->getTotpLastStep());
        self::assertSame([], $user->getRecoveryCodeHashes());
    }

    public function testEnablingTotpStoresTheEncryptedSecretAndRecoveryHashes(): void
    {
        $user = $this->userWithTotp();

        self::assertTrue($user->isTotpEnabled());
        self::assertSame('v1:encrypted-secret', $user->getTotpSecret());
        self::assertSame(['hash-1', 'hash-2'], $user->getRecoveryCodeHashes());
    }

    public function testEmptyTotpSecretIsRejected(): void
    {
        $this->expectException(InvalidUserException::class);

        $this->user()->enableTotp('', []);
    }

    public function testTotpStepsMustStrictlyIncrease(): void
    {
        $user = $this->userWithTotp();

        $user->recordTotpStep(10);
        self::assertSame(10, $user->getTotpLastStep());

        $this->expectException(InvalidUserException::class);
        $user->recordTotpStep(10);
    }

    public function testRecordingATotpStepRequiresTotp(): void
    {
        $this->expectException(InvalidUserException::class);

        $this->user()->recordTotpStep(1);
    }

    public function testRecoveryCodeHashesCanBeReplacedAfterAUse(): void
    {
        $user = $this->userWithTotp();

        $user->replaceRecoveryCodeHashes(['hash-2']);

        self::assertSame(['hash-2'], $user->getRecoveryCodeHashes());
    }

    public function testRecoveryCodesRequireTotp(): void
    {
        $this->expectException(InvalidUserException::class);

        $this->user()->replaceRecoveryCodeHashes(['hash-1']);
    }

    public function testDisablingTotpClearsEverythingAboutIt(): void
    {
        $user = $this->userWithTotp();
        $user->recordTotpStep(10);

        $user->disableTotp();

        self::assertFalse($user->isTotpEnabled());
        self::assertNull($user->getTotpSecret());
        self::assertSame(0, $user->getTotpLastStep());
        self::assertSame([], $user->getRecoveryCodeHashes());
    }

    public function testChangingThePasswordReplacesHashAndKdfParameters(): void
    {
        $user = $this->user();
        $newKdf = $this->kdf();
        $newHash = '$argon2id$v=19$m=16384,t=3,p=1$b3RoZXJzYWx0$b3RoZXJoYXNob3RoZXJoYXNo';

        $user->changePassword($newHash, $newKdf, null);

        self::assertSame($newKdf->toString(), $user->getKdfParameters()->toString());
        self::assertSame($newHash, $user->getPasswordHash());
    }

    public function testChangingThePasswordRotatesTheTotpSecretWithIt(): void
    {
        $user = $this->userWithTotp();

        $user->changePassword(self::HASH, $this->kdf(), 'v1:rotated-secret');

        self::assertSame('v1:rotated-secret', $user->getTotpSecret());
    }

    public function testChangingThePasswordWithTotpRequiresTheRotatedSecret(): void
    {
        $this->expectException(InvalidUserException::class);

        $this->userWithTotp()->changePassword(self::HASH, $this->kdf(), null);
    }

    public function testChangingThePasswordWithoutTotpRejectsAStraySecret(): void
    {
        $this->expectException(InvalidUserException::class);

        $this->user()->changePassword(self::HASH, $this->kdf(), 'v1:stray');
    }

    public function testRehashingReplacesOnlyTheHash(): void
    {
        $user = $this->userWithTotp();
        $kdf = $user->getKdfParameters()->toString();
        $newHash = '$argon2id$v=19$m=32768,t=4,p=1$b3RoZXJzYWx0$b3RoZXJoYXNob3RoZXJoYXNo';

        $user->rehashPassword($newHash);

        self::assertSame($newHash, $user->getPasswordHash());
        self::assertSame($kdf, $user->getKdfParameters()->toString());
        self::assertSame('v1:encrypted-secret', $user->getTotpSecret());
    }

    public function testRehashingRejectsANonArgon2idHash(): void
    {
        $this->expectException(InvalidUserException::class);

        $this->user()->rehashPassword('md5-of-something');
    }

    public function testChangingThePasswordRejectsANonArgon2idHash(): void
    {
        $this->expectException(InvalidUserException::class);

        $this->user()->changePassword('md5-of-something', $this->kdf(), null);
    }

    public function testHasNoRecoveryPhraseUntilOneIsEnabled(): void
    {
        $user = $this->user();

        self::assertFalse($user->hasRecovery());
        self::assertNull($user->getRecoveryKey());

        $at = new \DateTimeImmutable('2026-11-01T10:00:00+00:00');
        $user->enableRecovery('v1:AAAAwrappedkey', $at);

        self::assertTrue($user->hasRecovery());
        self::assertSame('v1:AAAAwrappedkey', $user->getRecoveryKey());
        self::assertEquals($at, $user->getRecoveryCreatedAt());

        $user->disableRecovery();
        self::assertFalse($user->hasRecovery());
        self::assertNull($user->getRecoveryCreatedAt());
    }

    public function testOnlyKeepsAnEncryptedRecoveryKey(): void
    {
        $this->expectException(InvalidUserException::class);

        $this->user()->enableRecovery('plain-key-bytes', new \DateTimeImmutable('2026-11-01T10:00:00+00:00'));
    }

    public function testChangingThePasswordDropsTheRecoveryKeyThatNoLongerFits(): void
    {
        $user = $this->user();
        $user->enableRecovery('v1:AAAAwrappedkey', new \DateTimeImmutable('2026-11-01T10:00:00+00:00'));

        $user->changePassword(self::HASH, $this->kdf(), null);

        self::assertFalse($user->hasRecovery());
    }
}
