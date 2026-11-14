<?php

declare(strict_types=1);

namespace App\Audit;

use App\Core\Connection\Exception\ForbiddenBastionException;
use App\Core\Connection\Tunnel\HostKey;
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
        return $this->guarded($context, $request->jump->host, $request->jump->port, fn (): TunnelEndpoint => $this->opener->open($request));
    }

    /**
     * L'empreinte de la clé d'hôte d'un bastion, pour que l'utilisateur la confirme. Une lecture qui aboutit ne laisse pas de trace.
     *
     * @throws ForbiddenBastionException
     * @throws TunnelFailedException
     */
    public function hostKey(AuditContext $context, string $host, int $port): HostKey
    {
        return $this->guarded($context, $host, $port, fn (): HostKey => $this->opener->hostKey($host, $port));
    }

    public function close(TunnelEndpoint $endpoint): void
    {
        $this->opener->close($endpoint);
    }

    /**
     * @template T
     *
     * @param \Closure(): T $action
     *
     * @return T
     */
    private function guarded(AuditContext $context, string $host, int $port, \Closure $action): mixed
    {
        $where = ['host' => mb_substr($host, 0, self::MAX_HOST_LENGTH), 'port' => $port];
        try {
            return $action();
        } catch (ForbiddenBastionException $refused) {
            $this->audit->record($context, AuditAction::TargetForbidden, AuditOutcome::Refused, details: $where);

            throw $refused;
        } catch (TunnelFailedException $failure) {
            // Le code seulement : jamais la sortie d'ssh, la clé ni l'empreinte.
            $this->audit->record($context, AuditAction::TunnelFailed, AuditOutcome::Failed, details: $where + ['reason' => $failure->reason]);

            throw $failure;
        }
    }
}
