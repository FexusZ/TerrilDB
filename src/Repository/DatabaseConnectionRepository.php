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

    public function flush(): void
    {
        $this->getEntityManager()->flush();
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
        return $this->findBy(['owner' => $owner], ['position' => 'ASC', 'createdAt' => 'ASC']);
    }

    /**
     * Le rang libre à la fin de la liste du propriétaire.
     */
    public function nextPosition(User $owner): int
    {
        $max = $this->createQueryBuilder('c')->select('MAX(c.position)')->where('c.owner = :owner')->setParameter('owner', $owner->getId(), 'uuid')->getQuery()->getSingleScalarResult();

        return null === $max ? 0 : (int) $max + 1;
    }
}
