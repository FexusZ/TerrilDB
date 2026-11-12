<?php

declare(strict_types=1);

namespace App\Tests\Core\Connection\Tunnel;

use App\Core\Connection\Tunnel\TunnelEndpoint;
use App\Core\Connection\Tunnel\TunnelOpener;
use App\Core\Connection\Tunnel\TunnelRequest;

/**
 * Un faux opener : compte les ouvertures et fermetures, ne touche à aucun réseau.
 */
final class RecordingTunnelOpener implements TunnelOpener
{
    public int $opened = 0;
    public int $closed = 0;

    public function open(TunnelRequest $request): TunnelEndpoint
    {
        ++$this->opened;

        return new TunnelEndpoint('abc', '/run/tunnels/abc/tunnel.sock');
    }

    public function close(TunnelEndpoint $endpoint): void
    {
        ++$this->closed;
    }
}
