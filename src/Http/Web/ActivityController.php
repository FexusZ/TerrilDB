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
    public function __construct(
        private AuditEventRepository $events,
        private ConnectionManager $connections,
        private WebSession $session,
    ) {
    }

    #[Route('/web/activity', methods: ['GET'])]
    public function activity(Request $request): JsonResponse
    {
        $limit = CursorPage::limit($request);
        $category = $request->query->get('category');
        if (null !== $category && !\in_array($category, array_unique(array_map(static fn (AuditAction $a): string => $a->category(), AuditAction::cases())), true)) {
            throw new BadRequestException('The category is not known.');
        }

        $events = $this->events->findForUser($this->session->user()->getId(), CursorPage::before($request), $limit, $category);

        return new JsonResponse(['events' => array_map(AuditPresenter::event(...), $events), 'next' => CursorPage::next($events, $limit)]);
    }

    #[Route('/web/connections/{id}/history', requirements: ['id' => '[0-9a-fA-F-]{36}'], methods: ['GET'])]
    public function history(string $id, Request $request): JsonResponse
    {
        $user = $this->session->user();
        $connectionId = Uuid::fromString($id);
        $this->connections->get($user, $connectionId);
        $limit = CursorPage::limit($request);

        $events = $this->events->findForUser($user->getId(), CursorPage::before($request), $limit, 'query', $connectionId);

        return new JsonResponse(['entries' => array_map(AuditPresenter::historyEntry(...), $events), 'next' => CursorPage::next($events, $limit)]);
    }
}
