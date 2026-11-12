<?php

declare(strict_types=1);

namespace App\Core\Connection\Tunnel;

use App\Core\Connection\Exception\ForbiddenBastionException;

/**
 * Ouvre un tunnel vers la cible d'une connexion à rebond SSH. Le tunnel lui-même vit hors de l'application, dans un
 * conteneur à part : ici on ne fait que le demander (et le retrouver s'il est déjà ouvert, voir docs/ssh-tunnel.md).
 */
interface TunnelOpener
{
    /**
     * @throws ForbiddenBastionException
     * @throws TunnelFailedException
     */
    public function open(TunnelRequest $request): TunnelEndpoint;

    public function close(TunnelEndpoint $endpoint): void;
}
