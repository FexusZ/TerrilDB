<?php

declare(strict_types=1);

namespace App\Tests\Crypto;

use App\Crypto\DerivedKey;
use App\Crypto\Exception\InvalidKeyException;
use App\Crypto\Exception\KeyWipedException;
use PHPUnit\Framework\TestCase;

final class DerivedKeyTest extends TestCase
{
    public function testExposesItsBytes(): void
    {
        $bytes = random_bytes(DerivedKey::LENGTH);

        self::assertSame($bytes, (new DerivedKey($bytes))->bytes());
    }

    public function testRejectsAWrongLength(): void
    {
        $this->expectException(InvalidKeyException::class);

        new DerivedKey('too short');
    }

    public function testWipedKeyCanNoLongerBeUsed(): void
    {
        $key = new DerivedKey(random_bytes(DerivedKey::LENGTH));

        $key->wipe();

        self::assertTrue($key->isWiped());
        $this->expectException(KeyWipedException::class);
        $key->bytes();
    }

    public function testWipeIsIdempotent(): void
    {
        $key = new DerivedKey(random_bytes(DerivedKey::LENGTH));

        $key->wipe();
        $key->wipe();

        self::assertTrue($key->isWiped());
    }

    public function testCannotBeSerialized(): void
    {
        $key = new DerivedKey(random_bytes(DerivedKey::LENGTH));

        $this->expectException(\LogicException::class);
        serialize($key);
    }

    public function testDoesNotLeakItsBytesInDumps(): void
    {
        $bytes = random_bytes(DerivedKey::LENGTH);
        $key = new DerivedKey($bytes);

        self::assertStringNotContainsString($bytes, print_r($key, true));
        self::assertStringNotContainsString($bytes, var_export($key, true));
    }
}
