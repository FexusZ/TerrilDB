<?php

declare(strict_types=1);

namespace App\Http\Web;

use App\ApiToken\TokenAdministration;
use App\Audit\AuditAction;
use App\Audit\AuditLog;
use App\Entity\ApiToken;
use App\Entity\AuditEvent;
use App\Entity\User;
use App\Invitation\Exception\NotAnAdministratorException;
use App\Repository\AuditEventRepository;
use App\Repository\UserRepository;
use Psr\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

/**
 * Administration (administrateurs seulement) : jetons d'API de tous les comptes, leur révocation, et le journal des
 * actions d'administration. Aucun secret, aucune clé, aucun contenu de connexion ne passe par ici.
 */
final readonly class AdminController
{
    public function __construct(
        private TokenAdministration $tokens,
        private AuditEventRepository $events,
        private UserRepository $users,
        private WebSession $session,
        private AuditLog $audit,
        private ClockInterface $clock,
    ) {
    }

    #[Route('/web/admin/tokens', methods: ['GET'])]
    public function tokens(): JsonResponse
    {
        $admin = $this->session->user();
        $now = \DateTimeImmutable::createFromInterface($this->clock->now());

        return new JsonResponse(['tokens' => array_map(
            static fn (ApiToken $token): array => AdminPresenter::token($token, $now),
            $this->tokens->list($admin),
        )]);
    }

    #[Route('/web/admin/tokens/{id}', requirements: ['id' => '[0-9a-fA-F-]{36}'], methods: ['DELETE'])]
    public function revoke(string $id): Response
    {
        $admin = $this->session->user();
        $token = $this->tokens->revoke($admin, Uuid::fromString($id));
        $this->audit->record(
            $this->session->auditContext($admin),
            AuditAction::AdminTokenRevoked,
            subject: $token->getId(),
            details: ['owner' => $token->getOwner()->getId()->toRfc4122()],
        );

        return new Response(null, 204);
    }

    #[Route('/web/admin/journal', methods: ['GET'])]
    public function journal(Request $request): JsonResponse
    {
        $admin = $this->session->user();
        if (!$admin->isAdmin()) {
            throw NotAnAdministratorException::create();
        }
        $limit = CursorPage::limit($request);
        $events = $this->events->findAdministration(CursorPage::before($request), $limit);
        $usernames = $this->usernames($events);

        return new JsonResponse([
            'events' => array_map(static fn (AuditEvent $event): array => AdminPresenter::journalEvent($event, $usernames), $events),
            'next' => CursorPage::next($events, $limit),
        ]);
    }

    /**
     * @param list<AuditEvent> $events
     *
     * @return array<string, string>
     */
    private function usernames(array $events): array
    {
        $ids = [];
        foreach ($events as $event) {
            $actor = $event->getUserId();
            if (null !== $actor) {
                $ids[$actor->toRfc4122()] = $actor;
            }
        }
        $names = [];
        /** @var list<User> $found */
        $found = [] === $ids ? [] : $this->users->findBy(['id' => array_values($ids)]);
        foreach ($found as $user) {
            $names[$user->getId()->toRfc4122()] = $user->getUsername();
        }

        return $names;
    }
}
