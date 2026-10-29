<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Crypto\KeyDerivation;
use App\Security\AuthPasswordHasher;
use PHPUnit\Framework\TestCase;
use Symfony\Component\PasswordHasher\Exception\InvalidPasswordException;
use Symfony\Component\PasswordHasher\PasswordHasherInterface;

final class AuthPasswordHasherTest extends TestCase
{
    private function hasher(): AuthPasswordHasher
    {
        return new AuthPasswordHasher(opsLimit: 3, memLimit: 16384);
    }

    public function testImplementsTheSymfonyHasherContract(): void
    {
        self::assertInstanceOf(PasswordHasherInterface::class, $this->hasher());
    }

    public function testDefaultCostIsModerate(): void
    {
        $hash = (new AuthPasswordHasher())->hash('correct horse');

        self::assertStringContainsString(
            \sprintf('m=%d,t=%d,', \SODIUM_CRYPTO_PWHASH_MEMLIMIT_MODERATE / 1024, \SODIUM_CRYPTO_PWHASH_OPSLIMIT_MODERATE),
            $hash,
        );
    }

    public function testHashIsArgon2idAndHidesThePassword(): void
    {
        $hash = $this->hasher()->hash('correct horse');

        self::assertStringStartsWith('$argon2id$', $hash);
        self::assertStringNotContainsString('correct horse', $hash);
    }

    public function testEachHashCarriesItsOwnSalt(): void
    {
        $hasher = $this->hasher();

        self::assertNotSame($hasher->hash('correct horse'), $hasher->hash('correct horse'));
    }

    public function testVerifiesTheRightPasswordOnly(): void
    {
        $hasher = $this->hasher();
        $hash = $hasher->hash('correct horse');

        self::assertTrue($hasher->verify($hash, 'correct horse'));
        self::assertFalse($hasher->verify($hash, 'wrong horse'));
        self::assertFalse($hasher->verify($hash, ''));
    }

    public function testGarbageHashNeverVerifies(): void
    {
        self::assertFalse($this->hasher()->verify('not a hash', 'correct horse'));
        self::assertFalse($this->hasher()->verify('', 'correct horse'));
    }

    public function testOnlyArgon2idHashesAreAccepted(): void
    {
        $hasher = $this->hasher();

        self::assertFalse($hasher->verify(password_hash('correct horse', \PASSWORD_BCRYPT), 'correct horse'));
        self::assertFalse($hasher->verify(password_hash('correct horse', \PASSWORD_ARGON2I), 'correct horse'));
    }

    public function testEmptyPasswordCannotBeHashed(): void
    {
        $this->expectException(InvalidPasswordException::class);

        $this->hasher()->hash('');
    }

    public function testOversizedPasswordCannotBeHashedNorVerified(): void
    {
        $hasher = $this->hasher();
        $hash = $hasher->hash('correct horse');
        $huge = str_repeat('a', 5000);

        self::assertFalse($hasher->verify($hash, $huge));

        $this->expectException(InvalidPasswordException::class);
        $hasher->hash($huge);
    }

    public function testNeedsRehashWhenTheCostWasRaised(): void
    {
        $hash = $this->hasher()->hash('correct horse');

        self::assertFalse($this->hasher()->needsRehash($hash));
        self::assertTrue((new AuthPasswordHasher(opsLimit: 4, memLimit: 32768))->needsRehash($hash));
    }

    public function testHashDoesNotRevealTheEncryptionKey(): void
    {
        $derivation = new KeyDerivation(opsLimit: 1, memLimit: 8192);
        $key = $derivation->derive('correct horse', $derivation->newParameters());

        $hash = $this->hasher()->hash('correct horse');

        self::assertStringNotContainsString($key->bytes(), $hash);
        self::assertStringNotContainsString(
            sodium_bin2base64($key->bytes(), \SODIUM_BASE64_VARIANT_ORIGINAL_NO_PADDING),
            $hash,
        );
    }
}
