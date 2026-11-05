<?php

declare(strict_types=1);

namespace App\Connection;

use App\Audit\AuditedTargetHosts;
use App\Core\Connection\Exception\InvalidConnectionParametersException;
use App\Core\Driver\DriverRegistry;
use App\Core\Driver\Exception\TargetConnectionFailedException;
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
    ) {
    }

    /**
     * @throws Exception\ConnectionNotFoundException
     * @throws Exception\ForbiddenTargetHostException
     * @throws InvalidConnectionParametersException   nom de base invalide
     * @throws TargetConnectionFailedException
     */
    public function open(User $owner, DerivedKey $key, Uuid $id, ?string $database = null): TargetConnection
    {
        $engine = $this->connections->get($owner, $id)->getEngine();
        $parameters = $this->connections->reveal($owner, $key, $id);
        if (null !== $database) {
            // Une connexion reste une base (PostgreSQL ne change pas de base en route) : on ouvre une connexion sur celle-là.
            $parameters = $parameters->withDatabase($database);
        }

        return $this->drivers->for($engine)->connect($engine, $this->hosts->pinned($owner, $parameters));
    }
}
