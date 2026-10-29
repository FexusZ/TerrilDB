<?php

declare(strict_types=1);

namespace App\Tests\Crypto;

use App\Crypto\Exception\EmptyPasswordException;
use App\Crypto\KdfParameters;
use App\Crypto\KeyDerivation;
use PHPUnit\Framework\TestCase;

final class KeyDerivationTest extends TestCase
{
    private function derivation(): KeyDerivation
    {
        return new KeyDerivation(opsLimit: 1, memLimit: 8192);
    }

    public function testNewParametersCarryTheConfiguredCostAndAFreshSalt(): void
    {
        $derivation = new KeyDerivation(opsLimit: 2, memLimit: 16384);

        $first = $derivation->newParameters();
        $second = $derivation->newParameters();

        self::assertSame(2, $first->opsLimit);
        self::assertSame(16384, $first->memLimit);
        self::assertNotSame($first->salt, $second->salt);
    }

    public function testDerivationIsDeterministicForTheSamePasswordAndParameters(): void
    {
        $parameters = $this->derivation()->newParameters();

        $a = $this->derivation()->derive('correct horse', $parameters);
        $b = $this->derivation()->derive('correct horse', $parameters);

        self::assertTrue(hash_equals($a->bytes(), $b->bytes()));
        self::assertSame(\SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES, \strlen($a->bytes()));
    }

    public function testItIsArgon2idWithTheStoredParameters(): void
    {
        $parameters = $this->derivation()->newParameters();

        $key = $this->derivation()->derive('correct horse', $parameters);

        $expected = sodium_crypto_pwhash(
            \SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES,
            'correct horse',
            $parameters->salt,
            $parameters->opsLimit,
            $parameters->memLimit,
            \SODIUM_CRYPTO_PWHASH_ALG_ARGON2ID13,
        );
        self::assertTrue(hash_equals($expected, $key->bytes()));
    }

    public function testADifferentPasswordGivesADifferentKey(): void
    {
        $parameters = $this->derivation()->newParameters();

        $a = $this->derivation()->derive('correct horse', $parameters);
        $b = $this->derivation()->derive('correct horsf', $parameters);

        self::assertFalse(hash_equals($a->bytes(), $b->bytes()));
    }

    public function testADifferentSaltGivesADifferentKey(): void
    {
        $a = $this->derivation()->derive('correct horse', $this->derivation()->newParameters());
        $b = $this->derivation()->derive('correct horse', $this->derivation()->newParameters());

        self::assertFalse(hash_equals($a->bytes(), $b->bytes()));
    }

    public function testStoredParametersWinOverTheConfiguredCost(): void
    {
        $stored = new KdfParameters(opsLimit: 2, memLimit: 16384, salt: random_bytes(KdfParameters::SALT_BYTES));

        $viaCheapDerivation = $this->derivation()->derive('correct horse', $stored);
        $viaOtherDerivation = (new KeyDerivation(opsLimit: 3, memLimit: 32768))->derive('correct horse', $stored);

        self::assertTrue(hash_equals($viaCheapDerivation->bytes(), $viaOtherDerivation->bytes()));
    }

    public function testRejectsAnEmptyPassword(): void
    {
        $this->expectException(EmptyPasswordException::class);

        $this->derivation()->derive('', $this->derivation()->newParameters());
    }
}
