<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\User;
use App\Invitation\Exception\NotAnAdministratorException;
use App\Repository\UserRepository;
use App\Security\Exception\AccountNotFoundException;
use App\Security\Exception\CannotDisableAccountException;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Gestion des comptes par un administrateur : liste, désactivation, réactivation. Un compte désactivé ne se
 * connecte plus, ses sessions et ses jetons sont refusés, mais rien n'est supprimé : la réactivation rétablit tout.
 * L'administrateur ne lit jamais les données chiffrées d'un autre compte : ce service ne les touche pas.
 */
final readonly class AccountAdministration
{
    public function __construct(
        private UserRepository $users,
        private EntityManagerInterface $entityManager,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @return list<User>
     */
    public function list(User $admin): array
    {
        $this->assertAdmin($admin);

        return $this->users->findAllByAge();
    }

    /**
     * @return bool vrai si le compte a changé d'état (déjà désactivé : rien à faire, rien à journaliser)
     */
    public function disable(User $admin, Uuid $id): bool
    {
        $this->assertAdmin($admin);
        if ($admin->getId()->equals($id)) {
            throw CannotDisableAccountException::self();
        }
        $target = $this->find($id);
        if ($target->isDisabled()) {
            return false;
        }
        if (!$target->isAdmin()) {
            $target->disable(\DateTimeImmutable::createFromInterface($this->clock->now()));
            $this->users->save($target);

            return true;
        }

        // Compter puis écrire dans UNE transaction qui verrouille les administrateurs actifs : deux désactivations
        // croisées se font attendre, la seconde recompte et voit qu'il n'en reste qu'un.
        $connection = $this->entityManager->getConnection();
        $connection->beginTransaction();
        try {
            $this->users->lockActiveAdmins();
            $this->entityManager->refresh($target);
            if ($target->isDisabled()) {
                $connection->rollBack();

                return false;
            }
            if ($this->users->countActiveAdmins() <= 1) {
                throw CannotDisableAccountException::lastAdministrator();
            }
            $target->disable(\DateTimeImmutable::createFromInterface($this->clock->now()));
            $this->entityManager->flush();
            $connection->commit();
        } catch (\Throwable $failure) {
            if ($connection->isTransactionActive()) {
                $connection->rollBack();
            }

            throw $failure;
        }

        return true;
    }

    /**
     * @return bool vrai si le compte a changé d'état
     */
    public function enable(User $admin, Uuid $id): bool
    {
        $this->assertAdmin($admin);
        $target = $this->find($id);
        if (!$target->isDisabled()) {
            return false;
        }

        $target->enable();
        $this->users->save($target);

        return true;
    }

    public function find(Uuid $id): User
    {
        return $this->users->find($id) ?? throw AccountNotFoundException::create();
    }

    private function assertAdmin(User $user): void
    {
        if (!$user->isAdmin()) {
            throw NotAnAdministratorException::create();
        }
    }
}
