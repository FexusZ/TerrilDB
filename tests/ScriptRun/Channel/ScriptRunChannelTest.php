<?php

declare(strict_types=1);

namespace App\Tests\ScriptRun\Channel;

use App\ScriptRun\Channel\ScriptRunChannel;
use PHPUnit\Framework\TestCase;

final class ScriptRunChannelTest extends TestCase
{
    public function testAFrameCarriesItsMessageAndItsMac(): void
    {
        $channel = new ScriptRunChannel('secret-of-the-installation');
        $frame = $channel->frame('{"v":1}');

        self::assertMatchesRegularExpression('/^[0-9a-f]{64}\n\{"v":1\}\n$/D', $frame);
        self::assertSame('{"v":1}', $channel->open($frame));
    }

    public function testAnotherSecretCannotForgeAFrame(): void
    {
        $forged = (new ScriptRunChannel('another-secret'))->frame('{"v":1}');

        self::assertNull((new ScriptRunChannel('secret-of-the-installation'))->open($forged));
    }

    public function testATamperedMessageIsRefused(): void
    {
        $channel = new ScriptRunChannel('secret-of-the-installation');
        $frame = $channel->frame('{"v":1,"script":"SELECT 1"}');

        self::assertNull($channel->open(str_replace('SELECT 1', 'DROP DATABASE x', $frame)));
    }

    public function testBrokenFramesAreRefusedWithoutAnError(): void
    {
        $channel = new ScriptRunChannel('secret-of-the-installation');

        foreach (['', "\n", 'abc', "zz\n{}\n", str_repeat('a', 64)."\n", str_repeat('a', 64)."\n{}", str_repeat('0', 64)."\n{}\n"] as $frame) {
            self::assertNull($channel->open($frame), (string) json_encode($frame));
        }
    }

    public function testTheSecretIsNeverLeftInTheFrame(): void
    {
        $secret = 'secret-of-the-installation';

        self::assertStringNotContainsString($secret, (new ScriptRunChannel($secret))->frame('{"v":1}'));
        self::assertStringNotContainsString($secret, print_r(new ScriptRunChannel($secret), true));
    }

    public function testAnEmptySecretIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new ScriptRunChannel('');
    }
}
