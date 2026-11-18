<?php

declare(strict_types=1);

namespace App\Http\Web;

use App\Entity\ApiToken;
use App\Entity\AuditEvent;

/**
 * Ce qu'un administrateur voit des jetons des autres et du journal d'administration : des métadonnées seulement.
 * Jamais le texte du jeton, l'empreinte, la clé enveloppée, ni le contenu ou les identifiants des connexions.
 */
final class AdminPresenter
{
    /**
     * @return array<string, mixed>
     */
    public static function token(ApiToken $token, \DateTimeImmutable $now): array
    {
        $owner = $token->getOwner();

        return [
            'id' => $token->getId()->toRfc4122(),
            'name' => $token->getName(),
            'owner' => ['id' => $owner->getId()->toRfc4122(), 'username' => $owner->getUsername(), 'disabled' => $owner->isDisabled()],
            // Tous les droits du jeton, nom pour nom : un droit ajouté à TokenPermissions apparaît sans autre changement.
            'permissions' => get_object_vars($token->getPermissions()),
            'allConnections' => $token->coversAllConnections(),
            'connectionCount' => \count($token->getConnectionIds()),
            'status' => $token->isExpired($now) ? 'expired' : 'active',
            'createdAt' => $token->getCreatedAt()->format(\DATE_ATOM),
            'expiresAt' => $token->getExpiresAt()?->format(\DATE_ATOM),
            'lastUsedAt' => $token->getLastUsedAt()?->format(\DATE_ATOM),
        ];
    }

    /**
     * @param array<string, string> $usernames nom d'utilisateur par identifiant de compte
     *
     * @return array<string, mixed>
     */
    public static function journalEvent(AuditEvent $event, array $usernames): array
    {
        $actorId = $event->getUserId()?->toRfc4122();

        return [
            'id' => $event->getId()->toRfc4122(),
            'occurredAt' => $event->getOccurredAt()->format(\DATE_ATOM),
            'action' => $event->getAction()->value,
            'actor' => null === $actorId ? null : ['id' => $actorId, 'username' => $usernames[$actorId] ?? null],
            'subjectId' => $event->getSubjectId()?->toRfc4122(),
            'owner' => \is_string($event->getDetails()['owner'] ?? null) ? $event->getDetails()['owner'] : null,
        ];
    }
}
