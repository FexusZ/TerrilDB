<?php

declare(strict_types=1);

namespace App\Connection;

use App\Connection\Exception\ForbiddenTargetHostException;
use App\Connection\Exception\ReplicaUnavailableException;
use App\Core\Connection\ConnectionParameters;
use App\Core\Connection\Engine;
use App\Core\Connection\Exception\ForbiddenBastionException;
use App\Core\Diagnostics\Vitess\ReplicaAvailability;
use App\Core\Driver\ServerFlavour;
use App\Entity\User;

/**
 * Y a-t-il un réplica à lire ? Ouvre la cible SUR LE PRIMAIRE (jamais sur `@replica`, qui échouerait justement s'il n'y en a pas),
 * vérifie que c'est un vtgate puis lit `SHOW VITESS_TABLETS` : lecture du topo, rien n'est modifié. Ce qui ne peut pas être lu
 * (serveur injoignable, droit manquant) rend `Unknown` et ne refuse jamais rien.
 */
final readonly class VitessReplicaCheck implements ReplicaProbe
{
    public function __construct(private TargetOpener $opener)
    {
    }

    public function inspect(User $user, Engine $engine, ConnectionParameters $parameters): ReplicaAvailability
    {
        if (Engine::MySql !== $engine) {
            return ReplicaAvailability::NotVitess;
        }
        $target = null;
        try {
            $target = $this->opener->connect($user, $engine, $parameters->withReadReplica(false));
            if (ServerFlavour::Vitess !== $target->version()?->flavour) {
                return ReplicaAvailability::NotVitess;
            }

            return ReplicaAvailability::fromTablets($parameters->database, $target->diagnostics()->tablets()->tablets());
        } catch (ForbiddenTargetHostException|ForbiddenBastionException $refused) {
            // Une adresse ou un bastion refusés restent un refus : la vérification ne les contourne jamais.
            throw $refused;
        } catch (\DomainException|\RuntimeException) {
            // Serveur injoignable, tunnel impossible, droit manquant, topo illisible : on ne sait pas, on ne refuse rien.
            return ReplicaAvailability::Unknown;
        } finally {
            try {
                $target?->close();
            } catch (\Throwable) {
                // Déjà fermée ou perdue : rien à ajouter.
            }
        }
    }

    /**
     * @throws ReplicaUnavailableException le serveur n'est pas un vtgate, ou n'a aucun réplica en service
     */
    public function require(User $user, Engine $engine, ConnectionParameters $parameters): void
    {
        $availability = $this->inspect($user, $engine, $parameters);
        if ($availability->refusesReplicaReading()) {
            throw ReplicaUnavailableException::for($availability);
        }
    }
}
