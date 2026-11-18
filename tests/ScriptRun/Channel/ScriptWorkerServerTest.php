<?php

declare(strict_types=1);

namespace App\Tests\ScriptRun\Channel;

use App\Core\Connection\ConnectionParameters;
use App\Core\Connection\Engine;
use App\Core\Query\WritePermissions;
use App\ScriptRun\Channel\ScriptRunChannel;
use App\ScriptRun\Channel\ScriptWorkerServer;
use App\ScriptRun\ScriptRunRequest;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

/**
 * Le canal de bout en bout dans un seul processus : le client écrit sans attendre, le test fait avancer le serveur d'un tour, puis
 * lit la réponse. Un vrai socket Unix, jamais un faux.
 */
final class ScriptWorkerServerTest extends TestCase
{
    private const SECRET = 'secret-of-the-installation';

    private string $dir;
    private string $socket;
    private ?ScriptWorkerServer $server = null;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir().'/tdb-worker-'.bin2hex(random_bytes(4));
        mkdir($this->dir, 0700);
        $this->socket = $this->dir.'/worker.sock';
    }

    protected function tearDown(): void
    {
        $this->server?->close();
        @unlink($this->socket);
        @rmdir($this->dir);
    }

    private function server(FakeChildSpawner $spawner, int $maxChildren = 2): ScriptWorkerServer
    {
        $this->server = new ScriptWorkerServer($this->socket, new ScriptRunChannel(self::SECRET), $spawner, $maxChildren, readTimeoutSeconds: 1);
        $this->server->listen();

        return $this->server;
    }

    private static function json(): string
    {
        return (new ScriptRunRequest(Uuid::v7(), Engine::PostgreSql, new ConnectionParameters('db.example.test', 5432, 'shop', 'app', 'pw-unique-1'), 'SELECT 1', new WritePermissions(), false, false, 10_000))->toJson();
    }

    /**
     * @param string $payload ce que le client écrit tel quel
     */
    private function send(ScriptWorkerServer $server, string $payload): string
    {
        $client = stream_socket_client('unix://'.$this->socket, $errno, $error, 2);
        self::assertNotFalse($client, "connect: $error");
        stream_set_blocking($client, false);
        @fwrite($client, $payload);
        stream_set_blocking($client, true);
        $server->tick(500);
        stream_set_timeout($client, 2);
        $reply = (string) fgets($client, 64);
        fclose($client);

        return trim($reply);
    }

    public function testAValidMessageIsAcceptedAndHandedToANewProcess(): void
    {
        $spawner = new FakeChildSpawner();
        $server = $this->server($spawner);
        $json = self::json();

        $reply = $this->send($server, (new ScriptRunChannel(self::SECRET))->frame($json));

        self::assertSame('ok', $reply);
        self::assertSame([$json], $spawner->messages);
        self::assertSame(1, $server->liveChildren());
    }

    public function testAMessageWithAWrongMacIsRefusedAndNothingStarts(): void
    {
        $spawner = new FakeChildSpawner();
        $server = $this->server($spawner);

        $reply = $this->send($server, (new ScriptRunChannel('another-secret'))->frame(self::json()));

        self::assertSame('refused', $reply);
        self::assertSame([], $spawner->messages);
    }

    public function testAnAuthenticMessageThatIsNotARunRequestIsRefused(): void
    {
        $spawner = new FakeChildSpawner();
        $server = $this->server($spawner);

        $reply = $this->send($server, (new ScriptRunChannel(self::SECRET))->frame('{"v":1,"run":"not-a-uuid"}'));

        self::assertSame('refused', $reply);
        self::assertSame([], $spawner->messages);
    }

    public function testGarbageAndOversizedInputAreRefusedWithoutStartingAnything(): void
    {
        $spawner = new FakeChildSpawner();
        $server = $this->server($spawner);

        self::assertSame('refused', $this->send($server, "not a frame\n"));
        self::assertSame('refused', $this->send($server, str_repeat('a', ScriptRunRequest::MAX_JSON_BYTES + 500)."\n"));
        self::assertSame([], $spawner->messages);
    }

    public function testTheServerIsBusyWhenTheLimitOfProcessesIsReached(): void
    {
        $spawner = new FakeChildSpawner();
        $server = $this->server($spawner, maxChildren: 1);
        $channel = new ScriptRunChannel(self::SECRET);

        self::assertSame('ok', $this->send($server, $channel->frame(self::json())));
        self::assertSame('busy', $this->send($server, $channel->frame(self::json())));
        self::assertCount(1, $spawner->messages);
    }

    public function testAFinishedProcessFreesItsPlace(): void
    {
        $spawner = new FakeChildSpawner();
        $server = $this->server($spawner, maxChildren: 1);
        $channel = new ScriptRunChannel(self::SECRET);
        self::assertSame('ok', $this->send($server, $channel->frame(self::json())));

        $spawner->children[0]->running = false;
        $server->tick(10);

        self::assertSame(0, $server->liveChildren());
        self::assertSame('ok', $this->send($server, $channel->frame(self::json())));
    }

    public function testASilentClientDoesNotBlockTheServerForLong(): void
    {
        $spawner = new FakeChildSpawner();
        $server = $this->server($spawner);
        $silent = stream_socket_client('unix://'.$this->socket, $errno, $error, 2);
        self::assertNotFalse($silent);

        $started = microtime(true);
        $server->tick(100);

        self::assertLessThan(3.0, microtime(true) - $started, 'un client muet est lâché après le délai de lecture');
        fclose($silent);
    }

    public function testTheSocketIsPrivateAndAStaleOneIsReplaced(): void
    {
        file_put_contents($this->socket, 'leftover of a dead worker');
        $server = $this->server(new FakeChildSpawner());

        self::assertSame('0600', substr(\sprintf('%o', fileperms($this->socket)), -4));
        $server->close();
        self::assertFileDoesNotExist($this->socket);
    }
}
