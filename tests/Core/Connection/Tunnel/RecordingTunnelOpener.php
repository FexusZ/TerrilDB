<?php

declare(strict_types=1);

namespace App\Tests\Core\Connection\Tunnel;

use App\Core\Connection\Tunnel\HostKey;
use App\Core\Connection\Tunnel\TunnelEndpoint;
use App\Core\Connection\Tunnel\TunnelOpener;
use App\Core\Connection\Tunnel\TunnelRequest;

/**
 * Un faux opener : compte les ouvertures et fermetures, ne touche à aucun réseau.
 */
final class RecordingTunnelOpener implements TunnelOpener
{
    public int $opened = 0;
    public const FINGERPRINT = 'SHA256:nThbg6kXUpJWGl7E1IGOCspRomTxdCARLviKw6E5SY8';

    public int $closed = 0;
    public int $scanned = 0;
    public ?TunnelRequest $last = null;
    public ?\Throwable $failure = null;

    public function open(TunnelRequest $request): TunnelEndpoint
    {
        ++$this->opened;
        $this->last = $request;
        if (null !== $this->failure) {
            throw $this->failure;
        }

        return new TunnelEndpoint('abc', '/run/tunnels/abc/tunnel.sock');
    }

    public function hostKey(string $host, int $port): HostKey
    {
        ++$this->scanned;

        return new HostKey(self::FINGERPRINT, 'ed25519');
    }

    public function close(TunnelEndpoint $endpoint): void
    {
        ++$this->closed;
    }
}
