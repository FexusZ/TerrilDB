<?php

declare(strict_types=1);

namespace App\Tests\Crypto;

use App\Crypto\Exception\InvalidRecoveryPhraseException;
use App\Crypto\RecoveryPhrase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RecoveryPhraseTest extends TestCase
{
    private const FORMAT = '/^[0-9A-HJKMNP-TV-Z]{4}(-[0-9A-HJKMNP-TV-Z]{4}){7}$/D';

    public function testGeneratesEightGroupsOfFourUnambiguousCharacters(): void
    {
        $phrase = RecoveryPhrase::generate();

        self::assertMatchesRegularExpression(self::FORMAT, $phrase->toString());
    }

    public function testTwoPhrasesDiffer(): void
    {
        self::assertNotSame(RecoveryPhrase::generate()->toString(), RecoveryPhrase::generate()->toString());
    }

    public function testReadsWhatSomeoneTypedBack(): void
    {
        $phrase = RecoveryPhrase::generate();
        $typed = strtolower(str_replace('-', '  ', $phrase->toString()));

        $parsed = RecoveryPhrase::parse(" \t".$typed."\n");

        self::assertSame($phrase->toString(), $parsed->toString());
        self::assertSame($phrase->unlockSecret(), $parsed->unlockSecret());
    }

    public function testToleratesTheLookAlikeCharactersOfCrockfordBase32(): void
    {
        $parsed = RecoveryPhrase::parse('OIL0-1111-2222-3333-4444-5555-6666-7777');

        self::assertSame('0110-1111-2222-3333-4444-5555-6666-7777', $parsed->toString());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalid(): iterable
    {
        yield 'empty' => [''];
        yield 'too short' => ['ABCD-EFGH'];
        yield 'too long' => [str_repeat('A', 33)];
        yield 'forbidden character' => ['ABCD-EFGH-JKMN-PQRS-TVWX-YZ01-2345-678U'];
        yield 'punctuation' => ['ABCD-EFGH-JKMN-PQRS-TVWX-YZ01-2345-67!8'];
    }

    #[DataProvider('invalid')]
    public function testRefusesAnythingElse(string $text): void
    {
        $this->expectException(InvalidRecoveryPhraseException::class);

        RecoveryPhrase::parse($text);
    }

    public function testTheUnlockSecretIsDerivedAndStable(): void
    {
        $one = RecoveryPhrase::generate();
        $other = RecoveryPhrase::generate();

        self::assertSame($one->unlockSecret(), RecoveryPhrase::parse($one->toString())->unlockSecret());
        self::assertNotSame($one->unlockSecret(), $other->unlockSecret());
        self::assertSame(43, \strlen($one->unlockSecret()));
    }

    public function testTheUnlockSecretDoesNotContainThePhrase(): void
    {
        $text = 'ABCD-EFGH-JKMN-PQRS-TVWX-YZ01-2345-6789';
        $secret = RecoveryPhrase::parse($text)->unlockSecret();

        self::assertStringNotContainsString($text, $secret);
        self::assertStringNotContainsString(str_replace('-', '', $text), $secret);
        self::assertStringNotContainsString('ABCD-EFGH', $secret);
    }

    public function testDoesNotLeakThroughDebuggingOrSerialization(): void
    {
        $phrase = RecoveryPhrase::generate();

        self::assertStringNotContainsString($phrase->toString(), print_r($phrase, true));
        $this->expectException(\LogicException::class);
        serialize($phrase);
    }
}
