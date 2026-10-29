<?php

declare(strict_types=1);

namespace App\Tests\Crypto;

use App\Crypto\DerivedKey;
use App\Crypto\Exception\DecryptionFailedException;
use App\Crypto\Exception\KeyWipedException;
use App\Crypto\Exception\MalformedPayloadException;
use App\Crypto\Exception\UnsupportedFormatVersionException;
use App\Crypto\SecretCipher;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SecretCipherTest extends TestCase
{
    private function key(): DerivedKey
    {
        return new DerivedKey(random_bytes(DerivedKey::LENGTH));
    }

    private function flipByte(string $raw, int $offset): string
    {
        return substr_replace($raw, \chr(\ord($raw[$offset]) ^ 1), $offset, 1);
    }

    public function testRoundTrip(): void
    {
        $key = $this->key();
        $cipher = new SecretCipher();

        $blob = $cipher->encrypt('s3cret-credentials', $key);

        self::assertSame('s3cret-credentials', $cipher->decrypt($blob, $key));
    }

    public function testEmptyPlaintextRoundTrips(): void
    {
        $key = $this->key();
        $cipher = new SecretCipher();

        self::assertSame('', $cipher->decrypt($cipher->encrypt('', $key), $key));
    }

    public function testBlobIsVersionedPrintableAndHidesThePlaintext(): void
    {
        $blob = (new SecretCipher())->encrypt('s3cret-credentials', $this->key());

        self::assertStringStartsWith('v1:', $blob);
        self::assertMatchesRegularExpression('/^[\x20-\x7e]+$/', $blob);
        self::assertStringNotContainsString('s3cret', $blob);
    }

    public function testEveryEncryptionUsesAFreshNonce(): void
    {
        $key = $this->key();
        $cipher = new SecretCipher();

        $blobs = [];
        for ($i = 0; $i < 200; ++$i) {
            $blobs[] = $cipher->encrypt('same plaintext', $key);
        }

        self::assertCount(200, array_unique($blobs));
    }

    public function testWrongKeyIsRejected(): void
    {
        $cipher = new SecretCipher();
        $blob = $cipher->encrypt('s3cret', $this->key());

        $this->expectException(DecryptionFailedException::class);
        $cipher->decrypt($blob, $this->key());
    }

    public function testTamperedCiphertextIsRejected(): void
    {
        $key = $this->key();
        $cipher = new SecretCipher();
        $blob = $cipher->encrypt('s3cret', $key);

        $raw = sodium_base642bin(substr($blob, 3), \SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING);
        $raw = $this->flipByte($raw, \strlen($raw) - 1);
        $tampered = 'v1:'.sodium_bin2base64($raw, \SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING);

        $this->expectException(DecryptionFailedException::class);
        $cipher->decrypt($tampered, $key);
    }

    public function testTamperedNonceIsRejected(): void
    {
        $key = $this->key();
        $cipher = new SecretCipher();
        $blob = $cipher->encrypt('s3cret', $key);

        $raw = sodium_base642bin(substr($blob, 3), \SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING);
        $raw = $this->flipByte($raw, 0);
        $tampered = 'v1:'.sodium_bin2base64($raw, \SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING);

        $this->expectException(DecryptionFailedException::class);
        $cipher->decrypt($tampered, $key);
    }

    public function testCiphertextIsBoundToItsContext(): void
    {
        $key = $this->key();
        $cipher = new SecretCipher();
        $blob = $cipher->encrypt('s3cret', $key, context: 'connection-1');

        self::assertSame('s3cret', $cipher->decrypt($blob, $key, context: 'connection-1'));

        $this->expectException(DecryptionFailedException::class);
        $cipher->decrypt($blob, $key, context: 'connection-2');
    }

    public function testUnknownVersionIsRejected(): void
    {
        $key = $this->key();
        $cipher = new SecretCipher();
        $blob = $cipher->encrypt('s3cret', $key);

        $this->expectException(UnsupportedFormatVersionException::class);
        $cipher->decrypt('v99:'.substr($blob, 3), $key);
    }

    #[DataProvider('malformedBlobs')]
    public function testMalformedBlobIsRejected(string $blob): void
    {
        $this->expectException(MalformedPayloadException::class);

        (new SecretCipher())->decrypt($blob, $this->key());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function malformedBlobs(): iterable
    {
        yield 'empty' => [''];
        yield 'no version prefix' => ['AAAA'];
        yield 'not base64' => ['v1:!!!'];
        yield 'too short for nonce and tag' => ['v1:'.sodium_bin2base64('short', \SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING)];
    }

    public function testWipedKeyCannotEncryptOrDecrypt(): void
    {
        $key = $this->key();
        $cipher = new SecretCipher();
        $blob = $cipher->encrypt('s3cret', $key);
        $key->wipe();

        $this->expectException(KeyWipedException::class);
        $cipher->decrypt($blob, $key);
    }
}
