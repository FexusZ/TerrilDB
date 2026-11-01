<?php

declare(strict_types=1);

namespace App\Http\Web;

use App\Audit\AuditAction;
use App\Audit\AuditLog;
use App\Http\Json\JsonInput;
use App\Invitation\InvitationService;
use Psr\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

/**
 * Invitations à créer un compte (administrateurs seulement). Le texte de l'invitation n'est rendu qu'à la création.
 */
final readonly class InvitationsController
{
    public function __construct(
        private InvitationService $invitations,
        private WebSession $session,
        private AuditLog $audit,
        private ClockInterface $clock,
    ) {
    }

    #[Route('/web/invitations', methods: ['GET'])]
    public function list(): JsonResponse
    {
        $now = \DateTimeImmutable::createFromInterface($this->clock->now());

        return new JsonResponse(['invitations' => array_map(
            static fn ($invitation): array => InvitationPresenter::present($invitation, $now),
            $this->invitations->list($this->session->user()),
        )]);
    }

    #[Route('/web/invitations', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        $input = JsonInput::fromRequest($request);
        $note = $input->optionalString('note') ?? '';
        $lifetime = $input->optionalInt('lifetimeDays', InvitationService::DEFAULT_LIFETIME_DAYS) ?? InvitationService::DEFAULT_LIFETIME_DAYS;

        $issued = $this->invitations->issue($this->session->user(), $note, $lifetime);
        $this->audit->record($this->session->auditContext(), AuditAction::InvitationCreated, subject: $issued->entity->getId(), details: ['lifetimeDays' => $lifetime]);

        return new JsonResponse([
            'token' => $issued->token,
            'invitation' => InvitationPresenter::present($issued->entity, \DateTimeImmutable::createFromInterface($this->clock->now())),
        ], Response::HTTP_CREATED);
    }

    #[Route('/web/invitations/{id}', requirements: ['id' => '[0-9a-fA-F-]{36}'], methods: ['DELETE'])]
    public function revoke(string $id): Response
    {
        $user = $this->session->user();
        $uuid = Uuid::fromString($id);
        $this->invitations->revoke($user, $uuid);
        $this->audit->record($this->session->auditContext($user), AuditAction::InvitationRevoked, subject: $uuid);

        return new Response(status: Response::HTTP_NO_CONTENT);
    }
}
