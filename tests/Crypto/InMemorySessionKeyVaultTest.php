<?php

declare(strict_types=1);

namespace App\Tests\Crypto;

use App\Crypto\DerivedKey;
use App\Crypto\InMemorySessionKeyVault;
use App\Crypto\SessionKeyVault;
use PHPUnit\Framework\TestCase;

final class InMemorySessionKeyVaultTest extends TestCase
{
    private function key(): DerivedKey
    {
        return new DerivedKey(random_bytes(DerivedKey::LENGTH));
    }

    public function testImplementsTheVaultContract(): void
    {
        self::assertInstanceOf(SessionKeyVault::class, new InMemorySessionKeyVault());
    }

    public function testReturnsTheStoredKey(): void
    {
        $vault = new InMemorySessionKeyVault();
        $key = $this->key();

        $vault->put('session-1', $key);

        self::assertSame($key, $vault->get('session-1'));
    }

    public function testUnknownSessionHasNoKey(): void
    {
        self::assertNull((new InMemorySessionKeyVault())->get('nope'));
    }

    public function testSessionsAreIsolated(): void
    {
        $vault = new InMemorySessionKeyVault();
        $a = $this->key();
        $b = $this->key();

        $vault->put('a', $a);
        $vault->put('b', $b);

        self::assertSame($a, $vault->get('a'));
        self::assertSame($b, $vault->get('b'));
    }

    public function testForgetRemovesAndWipesTheKey(): void
    {
        $vault = new InMemorySessionKeyVault();
        $key = $this->key();
        $vault->put('session-1', $key);

        $vault->forget('session-1');

        self::assertNull($vault->get('session-1'));
        self::assertTrue($key->isWiped());
    }

    public function testForgettingAnUnknownSessionIsHarmless(): void
    {
        (new InMemorySessionKeyVault())->forget('nope');

        $this->expectNotToPerformAssertions();
    }

    public function testReplacingAKeyWipesThePreviousOne(): void
    {
        $vault = new InMemorySessionKeyVault();
        $old = $this->key();
        $new = $this->key();
        $vault->put('session-1', $old);

        $vault->put('session-1', $new);

        self::assertTrue($old->isWiped());
        self::assertFalse($new->isWiped());
        self::assertSame($new, $vault->get('session-1'));
    }

    public function testStoringTheSameKeyTwiceDoesNotWipeIt(): void
    {
        $vault = new InMemorySessionKeyVault();
        $key = $this->key();

        $vault->put('session-1', $key);
        $vault->put('session-1', $key);

        self::assertFalse($key->isWiped());
    }

    public function testAKeyWipedElsewhereIsNotServed(): void
    {
        $vault = new InMemorySessionKeyVault();
        $key = $this->key();
        $vault->put('session-1', $key);

        $key->wipe();

        self::assertNull($vault->get('session-1'));
    }
}
