<?php

declare(strict_types=1);

namespace App\Tests\ScriptRun\Channel;

use App\ScriptRun\Channel\ScriptRunChannel;
use App\ScriptRun\Channel\ScriptWorkerServer;
use PHPUnit\Framework\TestCase;

/**
 * Le client (la requête web) dans un VRAI autre processus, le serveur dans celui du test.
 */
final class ScriptRunDispatcherTest extends TestCase
{
    private string $dir;
    private string $socket;
    private ?ScriptWorkerServer $server = null;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir().'/tdb-disp-'.bin2hex(random_bytes(4));
        mkdir($this->dir, 0700);
        $this->socket = $this->dir.'/worker.sock';
    }

    protected function tearDown(): void
    {
        $this->server?->close();
        @unlink($this->socket);
        @rmdir($this->dir);
    }

    /**
     * Lance le client et fait tourner le serveur (s'il existe) jusqu'à la fin du client.
     */
    private function probe(string $secret): string
    {
        $process = proc_open([\PHP_BINARY, __DIR__.'/dispatch_probe.php', $this->socket, $secret], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        self::assertIsResource($process);
        $deadline = microtime(true) + 10;
        while (proc_get_status($process)['running'] && microtime(true) < $deadline) {
            $this->server?->tick(50);
        }
        $output = (string) stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);

        return trim($output);
    }

    private function listen(FakeChildSpawner $spawner, int $max = 2): void
    {
        $this->server = new ScriptWorkerServer($this->socket, new ScriptRunChannel('secret-of-the-installation'), $spawner, $max);
        $this->server->listen();
    }

    public function testADispatchIsAcceptedByTheWorker(): void
    {
        $spawner = new FakeChildSpawner();
        $this->listen($spawner);

        self::assertSame('ok', $this->probe('secret-of-the-installation'));
        self::assertCount(1, $spawner->messages);
    }

    public function testWithoutAWorkerTheDispatchSaysItIsUnavailable(): void
    {
        self::assertSame('ScriptWorkerUnavailableException', $this->probe('secret-of-the-installation'));
    }

    public function testAWorkerThatIsFullSaysBusy(): void
    {
        $spawner = new FakeChildSpawner();
        $this->listen($spawner, 0);

        self::assertSame('ScriptWorkerBusyException', $this->probe('secret-of-the-installation'));
        self::assertSame([], $spawner->messages);
    }

    public function testAWorkerThatDoesNotKnowTheSecretRefusesAndTheDispatchSaysUnavailable(): void
    {
        $spawner = new FakeChildSpawner();
        $this->listen($spawner);

        self::assertSame('ScriptWorkerUnavailableException', $this->probe('a-different-secret'));
        self::assertSame([], $spawner->messages);
    }
}
