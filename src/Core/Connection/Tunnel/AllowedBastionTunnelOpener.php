<?php

declare(strict_types=1);

namespace App\Core\Connection\Tunnel;

/**
 * Ne transmet au conteneur du tunnel que les demandes visant un bastion autorisé (le conteneur refait le même contrôle
 * de son côté : c'est lui qui sort sur le réseau).
 */
final readonly class AllowedBastionTunnelOpener implements TunnelOpener
{
    public function __construct(
        private TunnelOpener $inner,
        private BastionAllowList $allowed,
    ) {
    }

    public function open(TunnelRequest $request): TunnelEndpoint
    {
        $this->allowed->assertAllowed($request->jump);

        return $this->inner->open($request);
    }

    public function close(TunnelEndpoint $endpoint): void
    {
        $this->inner->close($endpoint);
    }
}
