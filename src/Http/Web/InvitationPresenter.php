<?php

declare(strict_types=1);

namespace App\Http\Web;

use App\Entity\Invitation;

final class InvitationPresenter
{
    /**
     * Ce qui se montre d'une invitation après sa création : jamais le jeton ni son empreinte.
     *
     * @return array<string, string|null>
     */
    public static function present(Invitation $invitation, \DateTimeImmutable $now): array
    {
        return [
            'id' => $invitation->getId()->toRfc4122(),
            'note' => $invitation->getNote(),
            'status' => null !== $invitation->getUsedAt() ? 'used' : ($invitation->isExpired($now) ? 'expired' : 'pending'),
            'createdAt' => $invitation->getCreatedAt()->format(\DATE_ATOM),
            'expiresAt' => $invitation->getExpiresAt()->format(\DATE_ATOM),
            'usedAt' => $invitation->getUsedAt()?->format(\DATE_ATOM),
            'usedBy' => $invitation->getUsedBy()?->getUsername(),
        ];
    }
}
