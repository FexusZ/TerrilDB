<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\ApiToken;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<ApiToken>
 */
final class ApiTokenRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ApiToken::class);
    }

    public function save(ApiToken $token): void
    {
        $entityManager = $this->getEntityManager();
        $entityManager->persist($token);
        $entityManager->flush();
    }

    /**
     * @param bool $flush faux pour grouper la suppression avec d'autres écritures dans la même transaction
     */
    public function remove(ApiToken $token, bool $flush = true): void
    {
        $entityManager = $this->getEntityManager();
        $entityManager->remove($token);
        if ($flush) {
            $entityManager->flush();
        }
    }

    public function findOwned(User $owner, Uuid $id): ?ApiToken
    {
        return $this->findOneBy(['id' => $id, 'owner' => $owner]);
    }

    /**
     * @return list<ApiToken> du plus récent au plus ancien
     */
    public function findAllOwnedBy(User $owner): array
    {
        return $this->findBy(['owner' => $owner], ['createdAt' => 'DESC']);
    }
}
