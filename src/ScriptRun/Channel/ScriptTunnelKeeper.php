<?php

declare(strict_types=1);

namespace App\ScriptRun\Channel;

use App\Connection\TunnelQuota;
use App\Core\Connection\Engine;
use App\Core\Connection\Exception\ForbiddenBastionException;
use App\Core\Connection\SshJump;
use App\Core\Connection\Tunnel\TooManyTunnelsException;
use App\Core\Connection\Tunnel\TunnelEndpoint;
use App\Core\Connection\Tunnel\TunnelFailedException;
use App\Core\Connection\Tunnel\TunnelOpener;
use App\Core\Connection\Tunnel\TunnelRequest;
use App\ScriptRun\ScriptRunRequest;
use Psr\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Le tunnel d'une exécution détachée (#169) : le worker l'ouvre pour le processus qui joue le script, le GARDE ouvert tant que
 * l'exécution vit, puis le referme. Le relais ferme un tunnel 300 s après la dernière DEMANDE d'ouverture, même si une connexion
 * s'en sert encore : une longue instruction perdrait donc son tunnel, d'où `renew()` (même demande, le relais répond « déjà ouvert »
 * et repart de zéro). Même plafond par compte que la console (`TunnelQuota`). Le bastion a déjà été jugé par la requête de lancement.
 */
final class ScriptTunnelKeeper
{
    /** Bien en dessous des 300 s du relais : un renouvellement manqué ou tardif n'a pas de conséquence. */
    public const RENEW_EVERY_SECONDS = 60;

    /** @var array<string, array{request: TunnelRequest, endpoint: TunnelEndpoint, user: Uuid, renewedAt: int}> par identifiant d'exécution */
    private array $held = [];

    public function __construct(
        private readonly TunnelOpener $tunnels,
        private readonly TunnelQuota $quota,
        private readonly ClockInterface $clock,
    ) {
    }

    /**
     * Ouvre le tunnel d'une connexion avec rebond et rend le message à donner au processus de l'exécution (le socket du tunnel en
     * plus) ; une connexion directe passe telle quelle.
     *
     * @throws ForbiddenBastionException
     * @throws TunnelFailedException
     * @throws TooManyTunnelsException
     */
    public function prepare(ScriptRunRequest $request): ScriptRunRequest
    {
        $transport = $request->parameters->transport;
        if (!$transport instanceof SshJump) {
            return $request;
        }
        $user = $request->audit->userId;
        if (null === $user) {
            throw new TunnelFailedException();
        }
        $parameters = $request->parameters;
        $tunnel = new TunnelRequest($transport, $parameters->host, $parameters->port, Engine::PostgreSql === $request->engine ? '.s.PGSQL.'.$parameters->port : 'tunnel.sock');
        $endpoint = $this->tunnels->open($tunnel);
        try {
            $this->quota->admit($user, $endpoint->id);
        } catch (TooManyTunnelsException $refused) {
            // Un tunnel qu'on vient d'ouvrir pour rien est refermé ; celui d'un autre usage n'est pas à nous.
            if (!$endpoint->reused) {
                $this->tunnels->close($endpoint);
            }

            throw $refused;
        }
        $this->held[$request->runId->toRfc4122()] = ['request' => $tunnel, 'endpoint' => $endpoint, 'user' => $user, 'renewedAt' => $this->now()];

        return $request->withTunnelSocket($endpoint->socketPath);
    }

    /**
     * À appeler souvent (la boucle du worker, toutes les secondes) : renouvelle les tunnels des exécutions vivantes dont l'échéance
     * approche. Un renouvellement qui échoue ne dit rien : l'exécution, si le tunnel est perdu, se termine en échec d'elle-même.
     */
    public function renew(): void
    {
        $now = $this->now();
        foreach ($this->held as $run => $entry) {
            if ($now - $entry['renewedAt'] < self::RENEW_EVERY_SECONDS) {
                continue;
            }
            $this->held[$run]['renewedAt'] = $now;
            try {
                $endpoint = $this->tunnels->open($entry['request']);
                $this->quota->admit($entry['user'], $endpoint->id);
            } catch (\Throwable) {
                // Voir plus haut.
            }
        }
    }

    /**
     * L'exécution est terminée : le tunnel qu'elle a ouvert se referme (un tunnel partagé avec un autre usage reste) et sa place est rendue.
     */
    public function release(string $runId): void
    {
        $entry = $this->held[$runId] ?? null;
        if (null === $entry) {
            return;
        }
        unset($this->held[$runId]);
        $this->quota->release($entry['user'], $entry['endpoint']->id);
        if (!$entry['endpoint']->reused) {
            $this->tunnels->close($entry['endpoint']);
        }
    }

    private function now(): int
    {
        return $this->clock->now()->getTimestamp();
    }
}
