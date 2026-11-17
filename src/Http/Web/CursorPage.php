<?php

declare(strict_types=1);

namespace App\Http\Web;

use App\Entity\AuditEvent;
use App\Http\Json\BadRequestException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Uid\Uuid;

/**
 * Paramètres de pagination par curseur des listes d'événements (`limit`, `before`) et curseur de la page suivante.
 */
final class CursorPage
{
    public const DEFAULT_LIMIT = 50;
    public const MAX_LIMIT = 200;

    public static function limit(Request $request): int
    {
        $raw = $request->query->get('limit');
        if (null === $raw) {
            return self::DEFAULT_LIMIT;
        }
        if (1 !== preg_match('/^\d{1,4}$/D', $raw) || (int) $raw < 1 || (int) $raw > self::MAX_LIMIT) {
            throw new BadRequestException('The limit must be between 1 and 200.');
        }

        return (int) $raw;
    }

    public static function before(Request $request): ?Uuid
    {
        $raw = $request->query->get('before');
        if (null === $raw) {
            return null;
        }
        if (!Uuid::isValid($raw)) {
            throw new BadRequestException('The cursor is not valid.');
        }

        return Uuid::fromString($raw);
    }

    /**
     * @param list<AuditEvent> $events
     */
    public static function next(array $events, int $limit): ?string
    {
        return [] !== $events && \count($events) >= $limit ? $events[array_key_last($events)]->getId()->toRfc4122() : null;
    }
}
