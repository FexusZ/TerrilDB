<?php

declare(strict_types=1);

namespace App\Http\Web;

use App\Entity\ApiToken;

final class TokenPresenter
{
    /**
     * Ce qui se montre d'un jeton après sa création : jamais le jeton, ni son empreinte, ni la clé enveloppée.
     *
     * @return array<string, array<int, string>|string|bool|null>
     */
    public static function present(ApiToken $token): array
    {
        return [
            'id' => $token->getId()->toRfc4122(),
            'name' => $token->getName(),
            'connectionIds' => $token->getConnectionIds(),
            'allConnections' => $token->coversAllConnections(),
            'createdAt' => $token->getCreatedAt()->format(\DATE_ATOM),
            'expiresAt' => $token->getExpiresAt()->format(\DATE_ATOM),
            'lastUsedAt' => $token->getLastUsedAt()?->format(\DATE_ATOM),
        ];
    }
}
