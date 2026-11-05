<?php

declare(strict_types=1);

namespace App\Connection;

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
        private TargetHostPolicy $hosts,
    ) {
    }

    /**
     * @throws Exception\ConnectionNotFoundException
     * @throws Exception\ForbiddenTargetHostException
     * @throws TargetConnectionFailedException
     */
    public function open(User $owner, DerivedKey $key, Uuid $id): TargetConnection
    {
        $engine = $this->connections->get($owner, $id)->getEngine();
        $parameters = $this->connections->reveal($owner, $key, $id);
        $this->hosts->assertAllowed($owner, $parameters);

        return $this->drivers->for($engine)->connect($engine, $parameters);
    }
}
