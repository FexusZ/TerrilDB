<?php

declare(strict_types=1);

namespace App\Http\Web;

use App\Entity\User;

/**
 * Ce qu'un administrateur voit d'un compte : le strict nécessaire à sa gestion. Ni e-mail ni rien de chiffré.
 */
final class AccountPresenter
{
    /**
     * @return array<string, bool|string|null>
     */
    public static function present(User $account, User $viewer): array
    {
        return [
            'id' => $account->getId()->toRfc4122(),
            'username' => $account->getUsername(),
            'role' => $account->getRole()->value,
            'totpEnabled' => $account->isTotpEnabled(),
            'lastLoginAt' => $account->getLastLoginAt()?->format(\DATE_ATOM),
            'disabled' => $account->isDisabled(),
            'current' => $account->getId()->equals($viewer->getId()),
        ];
    }
}
