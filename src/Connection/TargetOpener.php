<?php

declare(strict_types=1);

namespace App\Connection;

use App\Audit\AuditedTargetHosts;
use App\Core\Connection\Exception\InvalidConnectionParametersException;
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
    ) {
    }

    /**
     * @throws Exception\ConnectionNotFoundException
     * @throws Exception\ForbiddenTargetHostException
     * @throws InvalidConnectionParametersException   nom de base ou de schéma invalide
     * @throws TargetConnectionFailedException
     * @throws UnsupportedOperationException          connexion avec rebond ou proxy (pas encore ouvrable)
     */
    public function open(User $owner, DerivedKey $key, Uuid $id, ?string $database = null, ?string $schema = null): TargetConnection
    {
        $engine = $this->connections->get($owner, $id)->getEngine();
        $parameters = $this->connections->reveal($owner, $key, $id);
        if (null !== $parameters->transport) {
            // Le relais local qui porte le tunnel n'existe pas encore : joindre la base en direct contournerait le rebond voulu.
            throw UnsupportedOperationException::for($engine, 'connection through a jump or proxy');
        }
        if (null !== $database) {
            // Une connexion reste une base (PostgreSQL ne change pas de base en route) : on ouvre une connexion sur celle-là.
            $parameters = $parameters->withDatabase($database);
        }
        if (null !== $schema) {
            // Un schéma PostgreSQL se choisit à l'ouverture (search_path) : l'introspection et la console le suivent sans rien changer.
            $parameters = $parameters->withSchema($schema);
        }

        return $this->drivers->for($engine)->connect($engine, $this->hosts->pinned($owner, $parameters));
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
