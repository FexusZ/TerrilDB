<?php

declare(strict_types=1);

namespace App\Connection;

use App\Audit\AuditedTargetHosts;
use App\Audit\AuditedTunnels;
use App\Audit\RequestAuditContext;
use App\Core\Connection\ConnectionParameters;
use App\Core\Connection\Engine;
use App\Core\Connection\Exception\ForbiddenBastionException;
use App\Core\Connection\Exception\InvalidConnectionParametersException;
use App\Core\Connection\SshJump;
use App\Core\Connection\Tunnel\TooManyTunnelsException;
use App\Core\Connection\Tunnel\TunnelEndpoint;
use App\Core\Connection\Tunnel\TunnelFailedException;
use App\Core\Connection\Tunnel\TunnelRequest;
use App\Core\Driver\DriverRegistry;
use App\Core\Driver\Exception\TargetConnectionFailedException;
use App\Core\Driver\Exception\UnsupportedOperationException;
use App\Core\Driver\TargetConnection;
use App\Crypto\DerivedKey;
use App\Entity\User;
use Symfony\Component\Uid\Uuid;

/**
 * Ouvre la base cible d'une connexion enregistrée : déchiffre les paramètres avec la clé de l'utilisateur,
 * choisit le driver du moteur et se connecte. Les paramètres en clair ne vivent que le temps de l'ouverture.
 */
final readonly class TargetOpener
{
    public function __construct(
        private ConnectionManager $connections,
        private DriverRegistry $drivers,
        private AuditedTargetHosts $hosts,
        private AuditedTunnels $tunnels,
        private RequestAuditContext $contexts,
        private TunnelQuota $quota,
    ) {
    }

    /**
     * @throws Exception\ConnectionNotFoundException
     * @throws Exception\ForbiddenTargetHostException
     * @throws InvalidConnectionParametersException   nom de base ou de schéma invalide
     * @throws TargetConnectionFailedException
     * @throws ForbiddenBastionException
     * @throws TunnelFailedException
     * @throws UnsupportedOperationException          connexion avec rebond sur un moteur sans socket Unix
     */
    public function open(User $owner, DerivedKey $key, Uuid $id, ?string $database = null, ?string $schema = null): TargetConnection
    {
        $engine = $this->connections->get($owner, $id)->getEngine();
        $parameters = $this->connections->reveal($owner, $key, $id);
        if (null !== $database) {
            // Une connexion reste une base (PostgreSQL ne change pas de base en route) : on ouvre une connexion sur celle-là.
            $parameters = $parameters->withDatabase($database);
        }
        if (null !== $schema) {
            // Un schéma PostgreSQL se choisit à l'ouverture (search_path) : l'introspection et la console le suivent sans rien changer.
            $parameters = $parameters->withSchema($schema);
        }

        return $this->connect($owner, $engine, $parameters);
    }

    /**
     * Ouvre une cible depuis des paramètres déjà en main (essai d'une connexion non enregistrée comprise), par la seule voie
     * permise : la règle d'adresses d'abord, puis, pour un rebond, le tunnel du conteneur dédié. Jamais en direct vers la
     * base d'une connexion à rebond.
     *
     * @throws Exception\ForbiddenTargetHostException
     * @throws ForbiddenBastionException              bastion absent de la liste de l'administrateur
     * @throws TunnelFailedException                  le tunnel n'a pas pu s'ouvrir (la raison seule, un code stable)
     * @throws TargetConnectionFailedException
     * @throws UnsupportedOperationException          moteur qui ne sait pas passer par un socket Unix
     * @throws TooManyTunnelsException                le compte tient déjà son plafond de tunnels (`TunnelQuota`)
     */
    public function connect(User $owner, Engine $engine, ConnectionParameters $parameters): TargetConnection
    {
        $endpoint = null;

        return $this->establish($owner, $engine, $parameters, $endpoint);
    }

    /**
     * Essaie des paramètres (connexion non enregistrée) puis referme tout : le tunnel ouvert POUR l'essai est refermé et
     * libère sa place dans le plafond du compte, sauf s'il existait déjà (celui d'une connexion enregistrée : il se partage et
     * se referme tout seul après l'inactivité).
     *
     * @throws \Throwable les mêmes erreurs que `connect`
     */
    public function probe(User $owner, Engine $engine, ConnectionParameters $parameters): void
    {
        $endpoint = null;
        try {
            $this->establish($owner, $engine, $parameters, $endpoint)->close();
        } finally {
            if (null !== $endpoint && !$endpoint->reused) {
                $this->quota->release($owner->getId(), $endpoint->id);
                $this->tunnels->close($endpoint);
            }
        }
    }

    private function establish(User $owner, Engine $engine, ConnectionParameters $parameters, ?TunnelEndpoint &$opened): TargetConnection
    {
        $transport = $parameters->transport;
        if (null === $transport) {
            return $this->drivers->for($engine)->connect($engine, $this->hosts->pinned($owner, $parameters));
        }
        if (!$transport instanceof SshJump || !$engine->reachableThroughJump()) {
            throw UnsupportedOperationException::for($engine, 'connection through a jump');
        }

        // Le bastion ET la cible derrière lui sont jugés ; le relais résout le bastion lui-même (liste d'autorisation).
        $this->hosts->assertAllowed($owner, $parameters);
        $endpoint = $this->tunnels->open($this->contexts->for($owner), new TunnelRequest(
            $transport,
            $parameters->host,
            $parameters->port,
            Engine::PostgreSql === $engine ? '.s.PGSQL.'.$parameters->port : 'tunnel.sock',
        ));
        try {
            $this->quota->admit($owner->getId(), $endpoint->id);
        } catch (TooManyTunnelsException $refused) {
            // Un tunnel qu'on vient d'ouvrir pour rien est refermé ; celui d'un autre usage n'est pas à nous.
            if (!$endpoint->reused) {
                $this->tunnels->close($endpoint);
            }

            throw $refused;
        }
        $opened = $endpoint;

        return $this->drivers->for($engine)->connect($engine, $parameters->withSocket($endpoint->socketPath));
    }

    /**
     * La base que la connexion nomme elle-même, ou null si elle n'en nomme aucune (on joint alors le serveur seul).
     *
     * @throws Exception\ConnectionNotFoundException
     */
    public function defaultDatabase(User $owner, DerivedKey $key, Uuid $id): ?string
    {
        $database = $this->connections->reveal($owner, $key, $id)->database;

        return '' === $database ? null : $database;
    }
}
