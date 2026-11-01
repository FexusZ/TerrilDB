<?php

declare(strict_types=1);

namespace App\Audit;

use App\Audit\Exception\InvalidAuditEventException;
use App\Entity\AuditEvent;
use App\Repository\AuditEventRepository;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Uid\Uuid;

/**
 * Enregistre un événement d'audit. Une écriture qui échoue est consignée dans les journaux techniques mais ne fait
 * jamais échouer l'action en cours : se connecter ou exécuter une requête ne doit pas dépendre du journal. Un événement
 * mal formé (détail qui ressemble à un secret) est en revanche une erreur de programmation, remontée telle quelle.
 */
final readonly class AuditLog
{
    public function __construct(
        private AuditEventRepository $events,
        private ClockInterface $clock,
        private LoggerInterface $logger = new NullLogger(),
    ) {
    }

    /**
     * @param array<string, mixed> $details
     *
     * @throws InvalidAuditEventException
     */
    public function record(AuditContext $context, AuditAction $action, AuditOutcome $outcome = AuditOutcome::Success, ?Uuid $subject = null, array $details = []): void
    {
        $event = new AuditEvent(
            Uuid::v7(),
            \DateTimeImmutable::createFromInterface($this->clock->now()),
            $context->userId,
            $context->actor,
            $context->tokenId,
            $context->ip,
            $action,
            $outcome,
            $subject,
            $details,
        );

        try {
            $this->events->append($event);
        } catch (\Throwable $error) {
            $this->logger->error('Audit event could not be written.', ['action' => $action->value, 'error' => $error::class]);
        }
    }
}
