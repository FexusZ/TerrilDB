<?php

declare(strict_types=1);

namespace App\Connection;

use App\Connection\Exception\ConnectionNameAlreadyUsedException;
use App\Connection\Exception\ConnectionNotFoundException;
use App\Connection\Exception\InvalidConnectionOrderException;
use App\Core\Connection\ConnectionParameters;
use App\Core\Connection\Engine;
use App\Crypto\DerivedKey;
use App\Crypto\SecretCipher;
use App\Entity\ConnectionColor;
use App\Entity\DatabaseConnection;
use App\Entity\User;
use App\Repository\DatabaseConnectionRepository;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Psr\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Gestion des connexions d'un utilisateur. Les paramètres sont chiffrés avec sa clé (dérivée de son mot de
 * passe, gardée le temps de la session) : sans cette clé, ni le serveur ni la base ne peuvent les relire.
 *
 * Toutes les opérations sont cloisonnées par propriétaire, et une connexion d'autrui est indiscernable
 * d'une connexion inexistante.
 */
final readonly class ConnectionManager
{
    public function __construct(
        private SecretCipher $cipher,
        private DatabaseConnectionRepository $connections,
        private ClockInterface $clock,
    ) {
    }

    public function create(User $owner, DerivedKey $key, string $name, Engine $engine, ConnectionParameters $parameters, ?ConnectionColor $color = null): DatabaseConnection
    {
        $id = Uuid::v7();
        $connection = new DatabaseConnection(
            $id,
            $owner,
            $name,
            $engine,
            $this->encrypt($parameters, $key, ConnectionSecretContext::forId($id)),
            \DateTimeImmutable::createFromInterface($this->clock->now()),
        );
        $connection->setColor($color);
        // Une nouvelle connexion se met à la fin de la liste choisie par son propriétaire.
        $connection->moveTo($this->connections->nextPosition($owner));

        $this->save($connection);

        return $connection;
    }

    public function update(User $owner, DerivedKey $key, Uuid $id, string $name, Engine $engine, ConnectionParameters $parameters, ?ConnectionColor $color = null): DatabaseConnection
    {
        $connection = $this->owned($owner, $id);
        $connection->update(
            $name,
            $engine,
            $this->encrypt($parameters, $key, ConnectionSecretContext::forConnection($connection)),
            \DateTimeImmutable::createFromInterface($this->clock->now()),
        );
        $connection->setColor($color);

        $this->save($connection);

        return $connection;
    }

    /**
     * Les connexions citées passent en tête, dans l'ordre donné ; les autres suivent, dans leur ordre actuel (une connexion
     * créée entre-temps ailleurs ne se perd pas).
     *
     * @param list<Uuid> $ids
     *
     * @throws InvalidConnectionOrderException une connexion inconnue, répétée ou d'un autre compte
     */
    public function reorder(User $owner, array $ids): void
    {
        $owned = [];
        foreach ($this->connections->findAllOwnedBy($owner) as $connection) {
            $owned[$connection->getId()->toRfc4122()] = $connection;
        }

        $ordered = [];
        foreach ($ids as $id) {
            $key = $id->toRfc4122();
            if (!isset($owned[$key]) || isset($ordered[$key])) {
                throw InvalidConnectionOrderException::create();
            }
            $ordered[$key] = $owned[$key];
        }

        $position = 0;
        foreach ($ordered + $owned as $connection) {
            $connection->moveTo($position++);
        }
        $this->connections->flush();
    }

    public function get(User $owner, Uuid $id): DatabaseConnection
    {
        return $this->owned($owner, $id);
    }

    public function delete(User $owner, Uuid $id): void
    {
        $this->connections->remove($this->owned($owner, $id));
    }

    /**
     * @return list<DatabaseConnection>
     */
    public function list(User $owner): array
    {
        return $this->connections->findAllOwnedBy($owner);
    }

    public function reveal(User $owner, DerivedKey $key, Uuid $id): ConnectionParameters
    {
        $connection = $this->owned($owner, $id);
        $json = $this->cipher->decrypt($connection->getEncryptedParameters(), $key, ConnectionSecretContext::forConnection($connection));

        try {
            return ConnectionParameters::fromJson($json);
        } finally {
            sodium_memzero($json);
        }
    }

    private function owned(User $owner, Uuid $id): DatabaseConnection
    {
        return $this->connections->findOwned($owner, $id) ?? throw ConnectionNotFoundException::create();
    }

    private function encrypt(ConnectionParameters $parameters, DerivedKey $key, string $context): string
    {
        $json = $parameters->toJson();

        try {
            return $this->cipher->encrypt($json, $key, $context);
        } finally {
            sodium_memzero($json);
        }
    }

    private function save(DatabaseConnection $connection): void
    {
        try {
            $this->connections->save($connection);
        } catch (UniqueConstraintViolationException $e) {
            throw ConnectionNameAlreadyUsedException::create($e);
        }
    }
}
