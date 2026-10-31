<?php

declare(strict_types=1);

namespace App\Tests\Crypto;

use App\Crypto\DerivedKey;
use App\Crypto\Exception\DecryptionFailedException;
use App\Crypto\Exception\InvalidKeyException;
use App\Crypto\Exception\KeyWipedException;
use App\Crypto\SessionKeyWrapper;
use PHPUnit\Framework\TestCase;

final class SessionKeyWrapperTest extends TestCase
{
    private function key(): DerivedKey
    {
        return new DerivedKey(random_bytes(DerivedKey::LENGTH));
    }

    public function testGeneratedUnlockSecretsAreUniqueAndUrlSafe(): void
    {
        $wrapper = new SessionKeyWrapper();

        $first = $wrapper->generateUnlockSecret();
        $second = $wrapper->generateUnlockSecret();

        self::assertNotSame($first, $second);
        self::assertMatchesRegularExpression('/^[A-Za-z0-9_-]+$/', $first);
        self::assertSame(
            DerivedKey::LENGTH,
            \strlen(sodium_base642bin($first, \SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING)),
        );
    }

    public function testUnwrapRestoresTheKey(): void
    {
        $wrapper = new SessionKeyWrapper();
        $key = $this->key();
        $secret = $wrapper->generateUnlockSecret();

        $wrapped = $wrapper->wrap($key, $secret, 'session-1');

        self::assertSame($key->bytes(), $wrapper->unwrap($wrapped, $secret, 'session-1')->bytes());
    }

    public function testWrappedFormHidesTheKey(): void
    {
        $wrapper = new SessionKeyWrapper();
        $key = $this->key();

        $wrapped = $wrapper->wrap($key, $wrapper->generateUnlockSecret(), 'session-1');

        self::assertStringNotContainsString($key->bytes(), $wrapped);
        self::assertStringNotContainsString(sodium_bin2base64($key->bytes(), \SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING), $wrapped);
    }

    public function testUnwrappedKeyIsIndependentFromTheOriginal(): void
    {
        $wrapper = new SessionKeyWrapper();
        $key = $this->key();
        $expected = $key->bytes();
        $secret = $wrapper->generateUnlockSecret();
        $wrapped = $wrapper->wrap($key, $secret, 'session-1');

        $key->wipe();

        self::assertSame($expected, $wrapper->unwrap($wrapped, $secret, 'session-1')->bytes());
    }

    public function testStorageAloneIsUseless(): void
    {
        $wrapper = new SessionKeyWrapper();
        $wrapped = $wrapper->wrap($this->key(), $wrapper->generateUnlockSecret(), 'session-1');

        $this->expectException(DecryptionFailedException::class);

        $wrapper->unwrap($wrapped, $wrapper->generateUnlockSecret(), 'session-1');
    }

    public function testWrappedKeyIsBoundToItsSession(): void
    {
        $wrapper = new SessionKeyWrapper();
        $secret = $wrapper->generateUnlockSecret();
        $wrapped = $wrapper->wrap($this->key(), $secret, 'session-1');

        $this->expectException(DecryptionFailedException::class);

        $wrapper->unwrap($wrapped, $secret, 'session-2');
    }

    public function testMalformedUnlockSecretIsRejected(): void
    {
        $wrapper = new SessionKeyWrapper();

        $this->expectException(InvalidKeyException::class);

        $wrapper->wrap($this->key(), 'too-short', 'session-1');
    }

    public function testMalformedUnlockSecretIsRejectedOnUnwrap(): void
    {
        $wrapper = new SessionKeyWrapper();
        $wrapped = $wrapper->wrap($this->key(), $wrapper->generateUnlockSecret(), 'session-1');

        $this->expectException(InvalidKeyException::class);

        $wrapper->unwrap($wrapped, '!!not base64!!', 'session-1');
    }

    public function testWipedKeyCannotBeWrapped(): void
    {
        $wrapper = new SessionKeyWrapper();
        $key = $this->key();
        $key->wipe();

        $this->expectException(KeyWipedException::class);

        $wrapper->wrap($key, $wrapper->generateUnlockSecret(), 'session-1');
    }
}
