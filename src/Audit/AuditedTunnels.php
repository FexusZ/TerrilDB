<?php

declare(strict_types=1);

namespace App\Audit;

use App\Core\Connection\Exception\ForbiddenBastionException;
use App\Core\Connection\Tunnel\TunnelEndpoint;
use App\Core\Connection\Tunnel\TunnelFailedException;
use App\Core\Connection\Tunnel\TunnelOpener;
use App\Core\Connection\Tunnel\TunnelRequest;

/**
 * L'ouverture d'un tunnel qui garde la trace d'un bastion refusé (`target.forbidden`) : viser un bastion que
 * l'administrateur n'a pas déclaré ressemble à un sondage. Seuls l'adresse saisie et le port sont journalisés, jamais
 * l'identifiant SSH, la clé ni la cible derrière le bastion.
 */
final readonly class AuditedTunnels
{
    private const MAX_HOST_LENGTH = 255;

    public function __construct(
        private TunnelOpener $opener,
        private AuditLog $audit,
    ) {
    }

    /**
     * @throws ForbiddenBastionException
     * @throws TunnelFailedException
     */
    public function open(AuditContext $context, TunnelRequest $request): TunnelEndpoint
    {
        try {
            return $this->opener->open($request);
        } catch (ForbiddenBastionException $refused) {
            $this->audit->record(
                $context,
                AuditAction::TargetForbidden,
                AuditOutcome::Refused,
                details: ['host' => mb_substr($request->jump->host, 0, self::MAX_HOST_LENGTH), 'port' => $request->jump->port],
            );

            throw $refused;
        }
    }
}
