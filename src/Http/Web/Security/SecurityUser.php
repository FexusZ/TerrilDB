<?php

declare(strict_types=1);

namespace App\Http\Web\Security;

use App\Entity\User;
use Symfony\Component\Security\Core\User\EquatableInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Ce que Symfony garde en session : l'identifiant du compte et une empreinte de son mot de passe. Quand le mot
 * de passe change, l'empreinte change et les autres sessions ne sont plus reconnues à leur prochaine requête.
 * L'empreinte est un hash du hash : rien qui permette de retrouver ou de vérifier un mot de passe.
 */
final readonly class SecurityUser implements UserInterface, EquatableInterface
{
    /**
     * @param non-empty-string $id
     */
    private function __construct(
        private string $id,
        private string $passwordFingerprint,
    ) {
    }

    public static function fromUser(User $user): self
    {
        return new self($user->getId()->toRfc4122(), hash('sha256', $user->getPasswordHash()));
    }

    public function getRoles(): array
    {
        return ['ROLE_USER'];
    }

    /**
     * @return non-empty-string
     */
    public function getUserIdentifier(): string
    {
        return $this->id;
    }

    public function isEqualTo(UserInterface $user): bool
    {
        return $user instanceof self
            && $user->id === $this->id
            && hash_equals($this->passwordFingerprint, $user->passwordFingerprint);
    }
}
