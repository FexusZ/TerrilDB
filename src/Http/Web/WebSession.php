<?php

declare(strict_types=1);

namespace App\Http\Web;

use App\Crypto\DerivedKey;
use App\Crypto\SessionKeyVault;
use App\Entity\User;
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

    public function sessionId(): string
    {
        return $this->requests->getSession()->getId();
    }
}
