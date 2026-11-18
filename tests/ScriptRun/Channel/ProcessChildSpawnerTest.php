<?php

declare(strict_types=1);

namespace App\Tests\ScriptRun\Channel;

use App\ScriptRun\Channel\ProcessChildSpawner;
use PHPUnit\Framework\TestCase;

final class ProcessChildSpawnerTest extends TestCase
{
    private string $out;

    protected function setUp(): void
    {
        $this->out = sys_get_temp_dir().'/tdb-child-'.bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        @unlink($this->out);
    }

    private function spawner(string $code): ProcessChildSpawner
    {
        return new ProcessChildSpawner('/unused', [\PHP_BINARY, '-r', $code]);
    }

    private static function waitUntilDone(object $child): void
    {
        \assert($child instanceof \App\ScriptRun\Channel\ScriptChild);
        $deadline = microtime(true) + 10;
        while ($child->isRunning() && microtime(true) < $deadline) {
            usleep(20_000);
        }
    }

    public function testTheMessageReachesTheProcessByItsStandardInputWholeAndNeverInItsArguments(): void
    {
        $json = '{"v":1,"password":"pw-unique-3","script":"'.str_repeat('x', 300_000).'"}';
        $spawner = $this->spawner(\sprintf('file_put_contents(%s, stream_get_contents(STDIN)); file_put_contents(%s, implode(" ", $argv));', var_export($this->out, true), var_export($this->out.'.args', true)));

        $child = $spawner->spawn($json);
        self::waitUntilDone($child);

        self::assertSame($json, file_get_contents($this->out));
        self::assertStringNotContainsString('pw-unique-3', (string) file_get_contents($this->out.'.args'));
        @unlink($this->out.'.args');
    }

    public function testARunningProcessIsSeenAsRunningThenAsFinished(): void
    {
        $child = $this->spawner('stream_get_contents(STDIN); usleep(300000);')->spawn('{}');

        self::assertTrue($child->isRunning());
        self::waitUntilDone($child);
        self::assertFalse($child->isRunning());
    }
}
