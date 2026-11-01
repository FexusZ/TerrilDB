<?php

declare(strict_types=1);

namespace App\Http\Web;

use App\Audit\AuditAction;
use App\Audit\AuditLog;
use App\Http\Json\JsonInput;
use App\Invitation\Exception\MailNotSentException;
use App\Invitation\InvitationMailer;
use App\Invitation\InvitationService;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
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
        private InvitationMailer $mailer,
        private LoggerInterface $logger,
    ) {
    }

    #[Route('/web/invitations', methods: ['GET'])]
    public function list(): JsonResponse
    {
        $now = \DateTimeImmutable::createFromInterface($this->clock->now());

        return new JsonResponse([
            'emailEnabled' => $this->mailer->isEnabled(),
            'invitations' => array_map(
                static fn ($invitation): array => InvitationPresenter::present($invitation, $now),
                $this->invitations->list($this->session->user()),
            ),
        ]);
    }

    #[Route('/web/invitations', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        $input = JsonInput::fromRequest($request);
        $note = $input->optionalString('note') ?? '';
        $lifetime = $input->optionalInt('lifetimeDays', InvitationService::DEFAULT_LIFETIME_DAYS) ?? InvitationService::DEFAULT_LIFETIME_DAYS;

        $email = trim($input->optionalString('email') ?? '');
        // Vérifié avant d'émettre : une adresse refusée ou un envoi non configuré ne laissent pas d'invitation orpheline.
        $recipient = '' === $email ? null : $this->mailer->recipient($email);

        $user = $this->session->user();
        $issued = $this->invitations->issue($user, $note, $lifetime);

        $sent = false;
        if (null !== $recipient) {
            try {
                $this->mailer->send($recipient, $issued->token, $issued->entity->getExpiresAt(), $user->getUsername());
                $sent = true;
            } catch (MailNotSentException $failure) {
                // Seulement la nature de l'échec : le détail du transport peut citer l'adresse du destinataire.
                $this->logger->error('Invitation e-mail not sent.', ['reason' => ($failure->getPrevious() ?? $failure)::class]);
            }
        }
        $details = ['lifetimeDays' => $lifetime] + (null === $recipient ? [] : ['emailed' => $sent]);
        $this->audit->record($this->session->auditContext($user), AuditAction::InvitationCreated, subject: $issued->entity->getId(), details: $details);

        return new JsonResponse([
            'token' => $issued->token,
            'emailSent' => $sent,
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
