<?php

declare(strict_types=1);

namespace App\Tests\Crypto;

use App\Crypto\Exception\InvalidKdfParametersException;
use App\Crypto\Exception\UnsupportedFormatVersionException;
use App\Crypto\KdfParameters;
use PHPUnit\Framework\TestCase;

final class KdfParametersTest extends TestCase
{
    public function testRoundTripsThroughItsStorableRepresentation(): void
    {
        $salt = random_bytes(KdfParameters::SALT_BYTES);
        $parameters = new KdfParameters(opsLimit: 2, memLimit: 65536, salt: $salt);

        $restored = KdfParameters::fromString($parameters->toString());

        self::assertSame(KdfParameters::CURRENT_VERSION, $restored->version);
        self::assertSame(2, $restored->opsLimit);
        self::assertSame(65536, $restored->memLimit);
        self::assertSame($salt, $restored->salt);
    }

    public function testRepresentationCarriesNoRawBinary(): void
    {
        $parameters = new KdfParameters(opsLimit: 1, memLimit: 8192, salt: random_bytes(KdfParameters::SALT_BYTES));

        self::assertMatchesRegularExpression('/^[\x20-\x7e]+$/', $parameters->toString());
    }

    public function testRejectsAWrongSaltLength(): void
    {
        $this->expectException(InvalidKdfParametersException::class);

        new KdfParameters(opsLimit: 1, memLimit: 8192, salt: 'short');
    }

    /**
     * @return iterable<string, array{int, int}>
     */
    public static function outOfBoundsCosts(): iterable
    {
        yield 'ops too low' => [0, 8192];
        yield 'mem too low' => [1, 8191];
        yield 'ops too high' => [KdfParameters::MAX_OPS_LIMIT + 1, 8192];
        yield 'mem too high' => [1, KdfParameters::MAX_MEM_LIMIT + 1];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('outOfBoundsCosts')]
    public function testRejectsOutOfBoundsCosts(int $opsLimit, int $memLimit): void
    {
        $this->expectException(InvalidKdfParametersException::class);

        new KdfParameters($opsLimit, $memLimit, random_bytes(KdfParameters::SALT_BYTES));
    }

    public function testRejectsAnUnknownVersion(): void
    {
        $this->expectException(UnsupportedFormatVersionException::class);

        KdfParameters::fromString('99$1$8192$'.base64_encode(random_bytes(KdfParameters::SALT_BYTES)));
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('malformedRepresentations')]
    public function testRejectsAMalformedRepresentation(string $value): void
    {
        $this->expectException(InvalidKdfParametersException::class);

        KdfParameters::fromString($value);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function malformedRepresentations(): iterable
    {
        yield 'empty' => [''];
        yield 'missing parts' => ['1$1$8192'];
        yield 'non numeric cost' => ['1$a$8192$AAAAAAAAAAAAAAAAAAAAAA'];
        yield 'bad base64 salt' => ['1$1$8192$!!!'];
    }
}
