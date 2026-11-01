<?php

declare(strict_types=1);

namespace App\Http\Web;

use App\Crypto\DerivedKey;
use App\Crypto\SessionKeyVault;
use App\Entity\User;
use App\Http\Web\Security\ProgrammaticAuthenticator;
use App\Http\Web\Security\SecurityUser;
use App\Repository\UserRepository;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Uid\Uuid;

/**
 * Le compte connecté et sa clé de chiffrement pour la requête en cours.
 */
final readonly class WebSession
{
    public function __construct(
        private Security $security,
        private UserRepository $users,
        private SessionKeyVault $vault,
        private RequestStack $requests,
    ) {
    }

    public function user(): User
    {
        $identifier = $this->security->getUser()?->getUserIdentifier();
        $user = null === $identifier ? null : $this->users->find(Uuid::fromString($identifier));

        return $user ?? throw new LockedException();
    }

    /**
     * @throws LockedException si la clé n'est plus disponible : il faut se reconnecter
     */
    public function key(): DerivedKey
    {
        return $this->vault->get($this->sessionId()) ?? throw new LockedException();
    }

    /**
     * Ouvre la session du compte et confie la clé de chiffrement au coffre.
     *
     * La session est démarrée AVANT de lire son identifiant (non démarrée, elle n'en a pas encore : la clé
     * serait enveloppée avec un identifiant vide et ne pourrait plus être rouverte), puis son identifiant est
     * renouvelé : une session ouverte avant la connexion ne devient jamais celle de l'utilisateur.
     */
    public function open(User $user, DerivedKey $key): void
    {
        $session = $this->requests->getSession();
        $session->start();
        $this->security->login(SecurityUser::fromUser($user), ProgrammaticAuthenticator::class, 'main');
        $session->migrate(true);
        $this->vault->put($session->getId(), $key);
    }

    public function sessionId(): string
    {
        $session = $this->requests->getSession();
        $session->start();

        return $session->getId();
    }
}
