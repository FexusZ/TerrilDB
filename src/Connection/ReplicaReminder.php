<?php

declare(strict_types=1);

namespace App\Connection;

use App\Connection\Exception\ConnectionNotFoundException;
use App\Connection\Exception\ReplicaUnavailableException;
use App\Core\Connection\ConnectionParameters;
use App\Core\Connection\Engine;
use App\Core\Diagnostics\Vitess\ReplicaAvailability;
use App\Crypto\DerivedKey;
use App\Entity\User;
use Psr\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Le rappel hebdomadaire (#40) : une connexion Vitess qui n'avait AUCUN réplica à l'enregistrement est surveillée. À l'ouverture,
 * si la dernière recherche a plus d'une semaine, on cherche de nouveau (lecture du topo, rien ne change) ; un réplica apparu fait
 * proposer à l'interface d'activer la lecture sur réplica, JAMAIS de l'activer d'office (un retard de réplication peut surprendre).
 * Pas de tâche planifiée : la recherche se fait quand quelqu'un ouvre la connexion.
 */
final readonly class ReplicaReminder
{
    private const INTERVAL_SECONDS = 7 * 86_400;

    public function __construct(
        private ConnectionManager $connections,
        private ReplicaProbe $probe,
        private ClockInterface $clock,
    ) {
    }

    /**
     * À l'enregistrement et à la modification : une connexion MySQL qui ne lit pas sur réplica et dont le serveur est un Vitess
     * sans réplica est surveillée ; tout autre cas ne l'est pas (ou plus).
     */
    public function arm(User $user, Uuid $id, Engine $engine, ConnectionParameters $parameters): void
    {
        if ($parameters->readReplica || Engine::MySql !== $engine) {
            $this->connections->stopWatchingReplica($user, $id);

            return;
        }
        if (ReplicaAvailability::Missing === $this->probe->inspect($user, $engine, $parameters)) {
            $this->connections->watchReplica($user, $id, false);
        } else {
            $this->connections->stopWatchingReplica($user, $id);
        }
    }

    /**
     * Faut-il proposer d'activer la lecture sur réplica ? Cherche seulement si la dernière recherche a une semaine.
     *
     * @throws ConnectionNotFoundException
     */
    public function check(User $user, DerivedKey $key, Uuid $id): bool
    {
        $connection = $this->connections->get($user, $id);
        $checkedAt = $connection->getReplicaCheckedAt();
        if (null === $checkedAt) {
            return false;
        }
        if ($connection->isReplicaFound()) {
            return true;
        }
        if ($this->clock->now()->getTimestamp() - $checkedAt->getTimestamp() < self::INTERVAL_SECONDS) {
            return false;
        }

        $parameters = $this->connections->reveal($user, $key, $id);
        switch ($this->probe->inspect($user, $connection->getEngine(), $parameters->withReadReplica(false))) {
            case ReplicaAvailability::Available:
                $this->connections->watchReplica($user, $id, true);

                return true;
            case ReplicaAvailability::Missing:
                $this->connections->watchReplica($user, $id, false);

                return false;
            case ReplicaAvailability::NotVitess:
                $this->connections->stopWatchingReplica($user, $id);

                return false;
            default:
                // Serveur illisible : on ne sait rien, on réessaiera à la prochaine ouverture.
                return false;
        }
    }

    /**
     * « Plus tard » : le réplica est connu, on n'en reparle que dans une semaine.
     */
    public function later(User $user, Uuid $id): void
    {
        $this->connections->get($user, $id);
        $this->connections->watchReplica($user, $id, false);
    }

    /**
     * Active la lecture sur réplica (même vérification qu'à l'enregistrement) et met fin à la surveillance.
     *
     * @throws ReplicaUnavailableException le réplica a disparu, ou le serveur n'est plus un Vitess
     */
    public function enable(User $user, DerivedKey $key, Uuid $id): void
    {
        $connection = $this->connections->get($user, $id);
        $parameters = $this->connections->reveal($user, $key, $id)->withReadReplica(true);
        $availability = $this->probe->inspect($user, $connection->getEngine(), $parameters);
        if ($availability->refusesReplicaReading()) {
            throw ReplicaUnavailableException::for($availability);
        }

        $this->connections->update($user, $key, $id, $connection->getName(), $connection->getEngine(), $parameters, $connection->getColor());
        $this->connections->stopWatchingReplica($user, $id);
    }
}
