<?php

declare(strict_types=1);

namespace App\Invitation;

use App\Entity\Exception\InvalidInvitationException;
use App\Entity\Invitation;
use App\Entity\User;
use App\Invitation\Exception\InvalidInvitationTokenException;
use App\Invitation\Exception\InvitationNotFoundException;
use App\Invitation\Exception\NotAnAdministratorException;
use App\Repository\InvitationRepository;
use App\Security\UserRegistrar;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\OptimisticLockException;
use Psr\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Invitations à créer un compte. Seul un administrateur en émet, en consulte et en révoque ; le compte créé par
 * une invitation est toujours un membre. L'inscription et la consommation de l'invitation forment une seule
 * transaction : une inscription refusée (mot de passe faible, compte existant) ne brûle pas l'invitation.
 */
final readonly class InvitationService
{
    public const DEFAULT_LIFETIME_DAYS = 7;
    public const MAX_LIFETIME_DAYS = 30;

    public function __construct(
        private InvitationRepository $invitations,
        private UserRegistrar $registrar,
        private EntityManagerInterface $entityManager,
        private ClockInterface $clock,
    ) {
    }

    public function issue(User $admin, string $note, int $lifetimeDays = self::DEFAULT_LIFETIME_DAYS): IssuedInvitation
    {
        $this->assertAdmin($admin);
        if ($lifetimeDays < 1 || $lifetimeDays > self::MAX_LIFETIME_DAYS) {
            throw new InvalidInvitationException('The invitation lifetime must be between 1 and 30 days.');
        }

        $id = Uuid::v7();
        $secret = InvitationSecret::generate($id);
        $now = \DateTimeImmutable::createFromInterface($this->clock->now());
        $invitation = new Invitation($id, $admin, $note, $secret->verifier(), $now, $now->modify(\sprintf('+%d days', $lifetimeDays)));
        $this->invitations->save($invitation);

        return new IssuedInvitation($secret->toString(), $invitation);
    }

    /**
     * Une invitation mal formée, inconnue, au secret erroné, utilisée, expirée ou révoquée rend toujours la même erreur.
     */
    public function register(#[\SensitiveParameter] string $presented, string $email, string $username, #[\SensitiveParameter] string $password): User
    {
        $secret = InvitationSecret::parse($presented);

        $connection = $this->entityManager->getConnection();
        // Transaction manuelle : wrapInTransaction fermerait l'EntityManager à la première exception du domaine.
        $connection->beginTransaction();

        try {
            $now = \DateTimeImmutable::createFromInterface($this->clock->now());
            $invitation = $this->invitations->find($secret->id);
            if (null === $invitation || !$invitation->matches($secret->verifier()) || !$invitation->isUsable($now)) {
                throw InvalidInvitationTokenException::create();
            }

            $user = $this->registrar->register($email, $username, $password);
            $invitation->markUsed($user, $now);
            $this->entityManager->flush();
            $connection->commit();

            return $user;
        } catch (OptimisticLockException) {
            // Deux inscriptions simultanées avec la même invitation : la seconde perd.
            $connection->rollBack();

            throw InvalidInvitationTokenException::create();
        } catch (\Throwable $failure) {
            $connection->rollBack();

            throw $failure;
        }
    }

    public function revoke(User $admin, Uuid $id): void
    {
        $this->assertAdmin($admin);
        $invitation = $this->invitations->findOwned($admin, $id) ?? throw InvitationNotFoundException::create();
        $this->invitations->remove($invitation);
    }

    /**
     * @return list<Invitation> de la plus récente à la plus ancienne
     */
    public function list(User $admin): array
    {
        $this->assertAdmin($admin);

        return $this->invitations->findAllCreatedBy($admin);
    }

    private function assertAdmin(User $user): void
    {
        if (!$user->isAdmin()) {
            throw NotAnAdministratorException::create();
        }
    }
}
