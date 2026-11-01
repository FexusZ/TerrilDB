<?php

declare(strict_types=1);

namespace App\Http\Web;

use App\Entity\AuditEvent;

final class AuditPresenter
{
    /**
     * @return array<string, mixed>
     */
    public static function event(AuditEvent $event): array
    {
        return [
            'id' => $event->getId()->toRfc4122(),
            'occurredAt' => $event->getOccurredAt()->format(\DATE_ATOM),
            'action' => $event->getAction()->value,
            'category' => $event->getAction()->category(),
            'outcome' => $event->getOutcome()->value,
            'actor' => $event->getActor()->value,
            'ip' => $event->getIp(),
            'subjectId' => $event->getSubjectId()?->toRfc4122(),
            'tokenId' => $event->getTokenId()?->toRfc4122(),
            'details' => $event->getDetails(),
        ];
    }

    /**
     * Une exécution de requête, telle qu'elle apparaît dans l'historique d'une connexion.
     *
     * @return array<string, mixed>
     */
    public static function historyEntry(AuditEvent $event): array
    {
        $details = $event->getDetails();

        return [
            'id' => $event->getId()->toRfc4122(),
            'occurredAt' => $event->getOccurredAt()->format(\DATE_ATOM),
            'actor' => $event->getActor()->value,
            'outcome' => $event->getOutcome()->value,
            'sql' => $details['sql'] ?? '',
            'mode' => $details['mode'] ?? null,
            'rowCount' => $details['rowCount'] ?? null,
            'durationMs' => $details['durationMs'] ?? null,
            'reason' => $details['reason'] ?? null,
        ];
    }
}
