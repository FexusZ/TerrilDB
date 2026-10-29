<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Security\RecoveryCodeService;
use PHPUnit\Framework\TestCase;

final class RecoveryCodeServiceTest extends TestCase
{
    public function testGeneratesTenUniqueCodesInReadableGroups(): void
    {
        $generated = (new RecoveryCodeService())->generate();

        self::assertCount(10, $generated->codes);
        self::assertCount(10, array_unique($generated->codes));
        foreach ($generated->codes as $code) {
            self::assertMatchesRegularExpression('/^[0-9a-hjkmnp-tv-z]{4}(-[0-9a-hjkmnp-tv-z]{4}){3}$/', $code);
        }
    }

    public function testCodeCountIsConfigurable(): void
    {
        self::assertCount(3, (new RecoveryCodeService())->generate(3)->codes);
    }

    public function testHashesMatchTheCodesOneToOneAndHideThem(): void
    {
        $generated = (new RecoveryCodeService())->generate();

        self::assertCount(10, $generated->hashes);
        foreach ($generated->hashes as $index => $hash) {
            self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $hash);
            self::assertStringNotContainsString(str_replace('-', '', $generated->codes[$index]), $hash);
        }
        self::assertCount(10, array_unique($generated->hashes));
    }

    public function testConsumeRemovesOnlyTheMatchingHash(): void
    {
        $service = new RecoveryCodeService();
        $generated = $service->generate();

        $remaining = $service->consume($generated->codes[3], $generated->hashes);

        self::assertNotNull($remaining);
        self::assertCount(9, $remaining);
        self::assertNotContains($generated->hashes[3], $remaining);
        self::assertSame(
            array_values(array_diff_key($generated->hashes, [3 => true])),
            $remaining,
        );
    }

    public function testACodeWorksOnlyOnce(): void
    {
        $service = new RecoveryCodeService();
        $generated = $service->generate();

        $remaining = $service->consume($generated->codes[0], $generated->hashes);

        self::assertNotNull($remaining);
        self::assertNull($service->consume($generated->codes[0], $remaining));
    }

    public function testUnknownCodeIsRejected(): void
    {
        $service = new RecoveryCodeService();
        $generated = $service->generate();

        self::assertNull($service->consume('0000-0000-0000-0000', $generated->hashes));
        self::assertNull($service->consume('', $generated->hashes));
        self::assertNull($service->consume($generated->codes[0], []));
    }

    public function testInputIsForgivingAboutCaseSeparatorsAndLookalikes(): void
    {
        $service = new RecoveryCodeService();
        $hashes = [$service->hash('0123-4567-89ab-cdef')];

        self::assertSame([], $service->consume('0123-4567-89AB-CDEF', $hashes), 'majuscules');
        self::assertSame([], $service->consume(' 0123 4567 89ab cdef ', $hashes), 'espaces');
        self::assertSame([], $service->consume('0123456789abcdef', $hashes), 'sans tirets');
        self::assertSame([], $service->consume('o123-4567-89ab-cdef', $hashes), 'o pour 0');
        self::assertSame([], $service->consume('0l23-4567-89ab-cdef', $hashes), 'l pour 1');
    }

    public function testAPrefixOfACodeIsNotEnough(): void
    {
        $service = new RecoveryCodeService();
        $generated = $service->generate(1);

        self::assertNull($service->consume(substr($generated->codes[0], 0, 14), $generated->hashes));
    }
}
