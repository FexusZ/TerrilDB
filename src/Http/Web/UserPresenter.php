<?php

declare(strict_types=1);

namespace App\Http\Web;

use App\Entity\User;

final class UserPresenter
{
    /**
     * @return array<string, bool|int|string>
     */
    public static function present(User $user): array
    {
        return [
            'id' => $user->getId()->toRfc4122(),
            'username' => $user->getUsername(),
            'email' => $user->getEmail(),
            'totpEnabled' => $user->isTotpEnabled(),
            'recoveryCodesLeft' => \count($user->getRecoveryCodeHashes()),
            'createdAt' => $user->getCreatedAt()->format(\DATE_ATOM),
        ];
    }
}
