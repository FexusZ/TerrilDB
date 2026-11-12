<?php

declare(strict_types=1);

namespace App\Tests\Tunnel;

use App\Core\Connection\Exception\ForbiddenBastionException;
use App\Core\Connection\SshJump;
use App\Core\Connection\Tunnel\TunnelEndpoint;
use App\Core\Connection\Tunnel\TunnelFailedException;
use App\Core\Connection\Tunnel\TunnelRequest;
use App\Tunnel\UnixSocketTunnelOpener;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class UnixSocketTunnelOpenerTest extends TestCase
{
    private const KEY = "-----BEGIN OPENSSH PRIVATE KEY-----\nAAAAfaux\n-----END OPENSSH PRIVATE KEY-----\n";
    private const FINGERPRINT = 'SHA256:nThbg6kXUpJWGl7E1IGOCspRomTxdCARLviKw6E5SY8';

    private string $dir;
    /** @var resource */
    private $relay;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir().'/tdb-tunnel-'.bin2hex(random_bytes(6));
        mkdir($this->dir, 0700);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir.'/*') ?: [] as $file) {
            @unlink($file);
        }
        rmdir($this->dir);
    }

    /**
     * Un faux relais (processus fils) : accepte une connexion, range la requête reçue dans un fichier, répond `$reply`.
     */
    private function startRelay(string $reply): void
    {
        $process = proc_open(
            [\PHP_BINARY, __DIR__.'/fake-relay.php', $this->dir.'/control.sock', $this->dir.'/received.json', $reply],
            [1 => ['pipe', 'w']],
            $pipes,
        );
        self::assertIsResource($process);
        $this->relay = $process;
        self::assertSame("ready\n", fgets($pipes[1]));
    }

    private function waitRelay(): void
    {
        proc_close($this->relay);
    }

    private function opener(): UnixSocketTunnelOpener
    {
        return new UnixSocketTunnelOpener($this->dir.'/control.sock', timeoutSeconds: 3);
    }

    private function request(): TunnelRequest
    {
        return new TunnelRequest(new SshJump('bastion.example', 2222, 'tunnel', self::KEY, self::FINGERPRINT), 'db.internal', 5432, '.s.PGSQL.5432');
    }

    public function testItSendsTheRequestAndReturnsTheEndpoint(): void
    {
        $this->startRelay(json_encode(['ok' => true, 'id' => '0123456789abcdef0123456789abcdef', 'socket' => $this->dir.'/0123456789abcdef0123456789abcdef/.s.PGSQL.5432'], \JSON_THROW_ON_ERROR));

        $endpoint = $this->opener()->open($this->request());

        self::assertEquals(new TunnelEndpoint('0123456789abcdef0123456789abcdef', $this->dir.'/0123456789abcdef0123456789abcdef/.s.PGSQL.5432'), $endpoint);
        $this->waitRelay();
        $sent = json_decode((string) file_get_contents($this->dir.'/received.json'), true, flags: \JSON_THROW_ON_ERROR);
        self::assertSame([
            'op' => 'open',
            'host' => 'bastion.example',
            'port' => 2222,
            'user' => 'tunnel',
            'private_key' => self::KEY,
            'fingerprint' => self::FINGERPRINT,
            'target_host' => 'db.internal',
            'target_port' => 5432,
            'socket_name' => '.s.PGSQL.5432',
            'idle_seconds' => 300,
        ], $sent);
    }

    public function testAForbiddenBastionAnswerBecomesTheDomainRefusal(): void
    {
        $this->startRelay('{"ok":false,"error":"forbidden_bastion"}');

        $this->expectException(ForbiddenBastionException::class);
        $this->opener()->open($this->request());
    }

    public function testAHostKeyAnswerKeepsOnlyItsReason(): void
    {
        $this->startRelay('{"ok":false,"error":"host_key_changed","detail":"SHA256:leaked"}');

        try {
            $this->opener()->open($this->request());
            self::fail('failure expected');
        } catch (TunnelFailedException $failure) {
            self::assertSame(TunnelFailedException::HOST_KEY_CHANGED, $failure->reason);
            self::assertStringNotContainsString('leaked', $failure->getMessage());
        }
    }

    #[DataProvider('garbage')]
    public function testAnythingElseIsAGenericFailure(string $reply): void
    {
        $this->startRelay($reply);

        try {
            $this->opener()->open($this->request());
            self::fail('failure expected');
        } catch (TunnelFailedException $failure) {
            self::assertSame(TunnelFailedException::UNAVAILABLE, $failure->reason);
            self::assertStringNotContainsString('AAAAfaux', $failure->getMessage());
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function garbage(): iterable
    {
        yield 'pas du JSON' => ['bonjour'];
        yield 'raison inconnue' => ['{"ok":false,"error":"boom"}'];
        yield 'socket hors du dossier' => ['{"ok":true,"id":"abc","socket":"/etc/passwd"}'];
        yield 'socket avec ..' => ['{"ok":true,"id":"abc","socket":"/tmp/../etc/x"}'];
        yield 'champ manquant' => ['{"ok":true}'];
    }

    public function testWithoutRelayItIsAGenericFailure(): void
    {
        $this->expectException(TunnelFailedException::class);
        $this->opener()->open($this->request());
    }

    public function testCloseSendsTheIdentifierOnly(): void
    {
        $this->startRelay('{"ok":true}');

        $this->opener()->close(new TunnelEndpoint('0123456789abcdef0123456789abcdef', $this->dir.'/0123456789abcdef0123456789abcdef/tunnel.sock'));

        $this->waitRelay();
        self::assertSame('{"op":"close","id":"0123456789abcdef0123456789abcdef"}', trim((string) file_get_contents($this->dir.'/received.json')));
    }
}
