<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\DatabaseConnection;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * Toute lecture est cloisonnée par propriétaire : il n'existe volontairement aucun accès par identifiant seul,
 * pour qu'une connexion d'un autre utilisateur ne puisse pas être atteinte en devinant son identifiant.
 *
 * @extends ServiceEntityRepository<DatabaseConnection>
 */
final class DatabaseConnectionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, DatabaseConnection::class);
    }

    public function save(DatabaseConnection $connection): void
    {
        $entityManager = $this->getEntityManager();
        $entityManager->persist($connection);
        $entityManager->flush();
    }

    public function remove(DatabaseConnection $connection): void
    {
        $entityManager = $this->getEntityManager();
        $entityManager->remove($connection);
        $entityManager->flush();
    }

    public function findOwned(User $owner, Uuid $id): ?DatabaseConnection
    {
        return $this->findOneBy(['id' => $id, 'owner' => $owner]);
    }

    /**
     * @return list<DatabaseConnection>
     */
    public function findAllOwnedBy(User $owner): array
    {
        return $this->findBy(['owner' => $owner], ['name' => 'ASC']);
    }
}
