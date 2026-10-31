<?php

declare(strict_types=1);

namespace App\Connection;

use App\Connection\Exception\ConnectionNameAlreadyUsedException;
use App\Connection\Exception\ConnectionNotFoundException;
use App\Core\Connection\ConnectionParameters;
use App\Core\Connection\Engine;
use App\Crypto\DerivedKey;
use App\Crypto\SecretCipher;
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

    public function create(User $owner, DerivedKey $key, string $name, Engine $engine, ConnectionParameters $parameters): DatabaseConnection
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

        $this->save($connection);

        return $connection;
    }

    public function update(User $owner, DerivedKey $key, Uuid $id, string $name, Engine $engine, ConnectionParameters $parameters): DatabaseConnection
    {
        $connection = $this->owned($owner, $id);
        $connection->update(
            $name,
            $engine,
            $this->encrypt($parameters, $key, ConnectionSecretContext::forConnection($connection)),
            \DateTimeImmutable::createFromInterface($this->clock->now()),
        );

        $this->save($connection);

        return $connection;
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
