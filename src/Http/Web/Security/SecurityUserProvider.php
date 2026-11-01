<?php

declare(strict_types=1);

namespace App\Http\Web\Security;

use App\Repository\UserRepository;
use Symfony\Component\Security\Core\Exception\UnsupportedUserException;
use Symfony\Component\Security\Core\Exception\UserNotFoundException;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Core\User\UserProviderInterface;
use Symfony\Component\Uid\Uuid;

/**
 * @implements UserProviderInterface<SecurityUser>
 */
final readonly class SecurityUserProvider implements UserProviderInterface
{
    public function __construct(private UserRepository $users)
    {
    }

    public function loadUserByIdentifier(string $identifier): SecurityUser
    {
        if (!Uuid::isValid($identifier)) {
            throw new UserNotFoundException();
        }
        $user = $this->users->find(Uuid::fromString($identifier));
        if (null === $user) {
            throw new UserNotFoundException();
        }

        return SecurityUser::fromUser($user);
    }

    public function refreshUser(UserInterface $user): SecurityUser
    {
        if (!$user instanceof SecurityUser) {
            throw new UnsupportedUserException();
        }

        return $this->loadUserByIdentifier($user->getUserIdentifier());
    }

    public function supportsClass(string $class): bool
    {
        return SecurityUser::class === $class;
    }
}
