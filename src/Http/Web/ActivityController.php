<?php

declare(strict_types=1);

namespace App\Http\Web;

use App\Audit\AuditAction;
use App\Connection\ConnectionManager;
use App\Http\Json\BadRequestException;
use App\Repository\AuditEventRepository;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

/**
 * Lecture du journal d'audit par son propriétaire : l'activité du compte, et l'historique des requêtes d'une
 * connexion. On ne lit jamais que les événements du compte connecté.
 */
final readonly class ActivityController
{
    private const DEFAULT_LIMIT = 50;
    private const MAX_LIMIT = 200;

    public function __construct(
        private AuditEventRepository $events,
        private ConnectionManager $connections,
        private WebSession $session,
    ) {
    }

    #[Route('/web/activity', methods: ['GET'])]
    public function activity(Request $request): JsonResponse
    {
        $limit = self::limit($request);
        $category = $request->query->get('category');
        if (null !== $category && !\in_array($category, array_unique(array_map(static fn (AuditAction $a): string => $a->category(), AuditAction::cases())), true)) {
            throw new BadRequestException('The category is not known.');
        }

        $events = $this->events->findForUser($this->session->user()->getId(), self::before($request), $limit, $category);

        return new JsonResponse(['events' => array_map(AuditPresenter::event(...), $events), 'next' => self::next($events, $limit)]);
    }

    #[Route('/web/connections/{id}/history', requirements: ['id' => '[0-9a-fA-F-]{36}'], methods: ['GET'])]
    public function history(string $id, Request $request): JsonResponse
    {
        $user = $this->session->user();
        $connectionId = Uuid::fromString($id);
        $this->connections->get($user, $connectionId);
        $limit = self::limit($request);

        $events = $this->events->findForUser($user->getId(), self::before($request), $limit, 'query', $connectionId);

        return new JsonResponse(['entries' => array_map(AuditPresenter::historyEntry(...), $events), 'next' => self::next($events, $limit)]);
    }

    private static function limit(Request $request): int
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

    private static function before(Request $request): ?Uuid
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
     * @param list<\App\Entity\AuditEvent> $events
     */
    private static function next(array $events, int $limit): ?string
    {
        return [] !== $events && \count($events) >= $limit ? $events[array_key_last($events)]->getId()->toRfc4122() : null;
    }
}
