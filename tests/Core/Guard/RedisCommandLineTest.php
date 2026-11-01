<?php

declare(strict_types=1);

namespace App\Tests\Core\Guard;

use App\Core\Guard\RedisCommandLine;
use PHPUnit\Framework\TestCase;

final class RedisCommandLineTest extends TestCase
{
    public function testSplitsOnWhitespace(): void
    {
        $line = RedisCommandLine::parse("  get   user:1  \n");

        self::assertTrue($line->complete);
        self::assertSame([['get', 'user:1']], $line->commands);
    }

    public function testDoubleQuotesGroupAndUnescape(): void
    {
        $line = RedisCommandLine::parse('set k "a b \"c\" \\\\ \n \x41"');

        self::assertSame([['set', 'k', "a b \"c\" \\ \n A"]], $line->commands);
    }

    public function testSingleQuotesGroupAndOnlyKnowTheirOwnQuote(): void
    {
        $line = RedisCommandLine::parse("set k 'it\\'s \\n here'");

        self::assertSame([['set', 'k', "it's \\n here"]], $line->commands);
    }

    public function testAnEmptyQuotedArgumentIsKept(): void
    {
        self::assertSame([['set', 'k', '']], RedisCommandLine::parse('set k ""')->commands);
    }

    public function testEachNonEmptyLineIsACommand(): void
    {
        $line = RedisCommandLine::parse("get a\n\n  \nflushdb\n");

        self::assertSame([['get', 'a'], ['flushdb']], $line->commands);
    }

    public function testANewlineInsideQuotesDoesNotSplit(): void
    {
        self::assertSame([['set', 'k', "a\nb"]], RedisCommandLine::parse("set k \"a\nb\"")->commands);
    }

    public function testAnUnclosedQuoteMakesTheLineIncomplete(): void
    {
        self::assertFalse(RedisCommandLine::parse('set k "abc')->complete);
        self::assertFalse(RedisCommandLine::parse("set k 'abc")->complete);
    }

    public function testAQuoteGluedToTextIsNotAValidArgument(): void
    {
        self::assertFalse(RedisCommandLine::parse('set k "a"b')->complete);
    }

    public function testNothingButSpacesHasNoCommand(): void
    {
        $line = RedisCommandLine::parse("  \n\t ");

        self::assertTrue($line->complete);
        self::assertSame([], $line->commands);
    }
}
