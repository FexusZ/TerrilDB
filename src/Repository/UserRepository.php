<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Role;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<User>
 */
final class UserRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, User::class);
    }

    public function save(User $user): void
    {
        $entityManager = $this->getEntityManager();
        $entityManager->persist($user);
        $entityManager->flush();
    }

    /**
     * Connexion par e-mail ou nom d'utilisateur. Un identifiant contenant « @ » est toujours un e-mail :
     * les noms d'utilisateur n'en contiennent pas, il n'y a donc aucune ambiguïté.
     */
    public function findByLogin(string $login): ?User
    {
        $login = mb_strtolower(trim($login));
        if ('' === $login) {
            return null;
        }

        return $this->findOneBy(str_contains($login, '@') ? ['email' => $login] : ['username' => $login]);
    }

    /**
     * Tous les comptes, du plus ancien au plus récent (l'identifiant UUID v7 départage).
     *
     * @return list<User>
     */
    public function findAllByAge(): array
    {
        return $this->findBy([], ['createdAt' => 'ASC', 'id' => 'ASC']);
    }

    public function countActiveAdmins(): int
    {
        return $this->count(['role' => Role::Admin, 'disabledAt' => null]);
    }
}
