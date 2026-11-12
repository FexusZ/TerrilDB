<?php

declare(strict_types=1);

namespace App\Tests\Integration\Tunnel;

use App\Core\Connection\Exception\ForbiddenBastionException;
use App\Core\Connection\SshJump;
use App\Core\Connection\Tunnel\AllowedBastionTunnelOpener;
use App\Core\Connection\Tunnel\BastionAllowList;
use App\Core\Connection\Tunnel\TunnelEndpoint;
use App\Core\Connection\Tunnel\TunnelFailedException;
use App\Core\Connection\Tunnel\TunnelRequest;
use App\Tunnel\UnixSocketTunnelOpener;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Bout en bout : conteneur `tunnel` + bastion de test (profil « targets ») + base joignable UNIQUEMENT par le bastion.
 * Sauté si le relais ou le bastion ne tournent pas. La clé de test est générée au démarrage du bastion, jamais versionnée.
 */
#[Group('integration')]
final class SshTunnelEndToEndTest extends TestCase
{
    private const CONTROL = '/run/tunnels/control.sock';
    private const HIDDEN = 'target-behind-bastion';

    private string $key;
    private string $fingerprint;
    private UnixSocketTunnelOpener $relay;
    /** @var list<TunnelEndpoint> */
    private array $open = [];

    protected function setUp(): void
    {
        $key = @file_get_contents('/run/bastion-keys/client_key');
        $fingerprint = @file_get_contents('/run/bastion-keys/host_fingerprint');
        if (false === $key || false === $fingerprint || !file_exists(self::CONTROL)) {
            self::markTestSkipped('Le conteneur tunnel et le bastion de test ne tournent pas (profil targets).');
        }
        $this->key = $key;
        $this->fingerprint = trim($fingerprint);
        $this->relay = new UnixSocketTunnelOpener(self::CONTROL);
    }

    protected function tearDown(): void
    {
        foreach ($this->open as $endpoint) {
            $this->relay->close($endpoint);
        }
    }

    private function request(?string $fingerprint, string $socketName = '.s.PGSQL.5432', string $bastion = 'target-bastion'): TunnelRequest
    {
        return new TunnelRequest(new SshJump($bastion, 22, 'jump', $this->key, $fingerprint), self::HIDDEN, 5432, $socketName);
    }

    private function opener(): AllowedBastionTunnelOpener
    {
        return new AllowedBastionTunnelOpener($this->relay, BastionAllowList::fromString('target-bastion:22'));
    }

    private function open(TunnelRequest $request): TunnelEndpoint
    {
        return $this->open[] = $this->opener()->open($request);
    }

    private function pdo(TunnelEndpoint $endpoint): \PDO
    {
        $url = parse_url((string) getenv('TARGET_POSTGRES_URL'));
        self::assertIsArray($url);

        return new \PDO(
            'pgsql:host='.\dirname($endpoint->socketPath).';port=5432;dbname=shop;connect_timeout=5',
            rawurldecode($url['user'] ?? ''),
            rawurldecode($url['pass'] ?? ''),
            [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION],
        );
    }

    public function testTheTargetIsOnlyReachableThroughTheBastion(): void
    {
        self::assertSame(self::HIDDEN, gethostbyname(self::HIDDEN), 'la base cachée ne doit pas se résoudre depuis php');

        $endpoint = $this->open($this->request($this->fingerprint));

        $result = $this->pdo($endpoint)->query('SELECT 1');
        self::assertInstanceOf(\PDOStatement::class, $result);
        self::assertSame(1, (int) $result->fetchColumn());
    }

    public function testASecondOpeningReusesTheSameTunnel(): void
    {
        $first = $this->open($this->request($this->fingerprint));
        $second = $this->open($this->request($this->fingerprint));

        self::assertSame($first->id, $second->id);
    }

    public function testClosingRemovesTheSocket(): void
    {
        $endpoint = $this->open($this->request($this->fingerprint));
        self::assertTrue(file_exists($endpoint->socketPath));

        $this->relay->close($endpoint);

        self::assertFalse(file_exists($endpoint->socketPath));
    }

    public function testNoFingerprintMeansNoTrustOnFirstUse(): void
    {
        try {
            $this->open($this->request(null));
            self::fail('refused expected');
        } catch (TunnelFailedException $failure) {
            self::assertSame(TunnelFailedException::HOST_KEY_UNCONFIRMED, $failure->reason);
        }
    }

    public function testAnotherFingerprintIsAChangedHostKey(): void
    {
        try {
            $this->open($this->request('SHA256:'.str_repeat('A', 43)));
            self::fail('refused expected');
        } catch (TunnelFailedException $failure) {
            self::assertSame(TunnelFailedException::HOST_KEY_CHANGED, $failure->reason);
        }
    }

    public function testTheRelayAppliesItsOwnAllowListEvenIfTheApplicationDoesNot(): void
    {
        $this->expectException(ForbiddenBastionException::class);
        $this->relay->open(new TunnelRequest(new SshJump('other.example', 22, 'jump', $this->key, $this->fingerprint), self::HIDDEN, 5432));
    }

    public function testAWrongKeyFailsGenerically(): void
    {
        $wrong = str_replace('AAAA', 'AAAB', $this->key);
        try {
            $this->open(new TunnelRequest(new SshJump('target-bastion', 22, 'jump', $wrong, $this->fingerprint), self::HIDDEN, 5432));
            self::fail('failure expected');
        } catch (TunnelFailedException $failure) {
            self::assertSame(TunnelFailedException::UNAVAILABLE, $failure->reason);
        }
    }
}
