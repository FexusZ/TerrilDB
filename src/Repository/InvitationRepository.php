<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Invitation;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<Invitation>
 */
final class InvitationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Invitation::class);
    }

    public function save(Invitation $invitation): void
    {
        $entityManager = $this->getEntityManager();
        $entityManager->persist($invitation);
        $entityManager->flush();
    }

    public function remove(Invitation $invitation): void
    {
        $entityManager = $this->getEntityManager();
        $entityManager->remove($invitation);
        $entityManager->flush();
    }

    public function findOwned(User $admin, Uuid $id): ?Invitation
    {
        return $this->findOneBy(['id' => $id, 'createdBy' => $admin]);
    }

    /**
     * @return list<Invitation> de la plus récente à la plus ancienne
     */
    public function findAllCreatedBy(User $admin): array
    {
        return $this->findBy(['createdBy' => $admin], ['createdAt' => 'DESC', 'id' => 'DESC']);
    }
}
