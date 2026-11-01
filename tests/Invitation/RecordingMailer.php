<?php

declare(strict_types=1);

namespace App\Tests\Invitation;

use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\RawMessage;

/**
 * Double de test : garde les messages envoyés, ou échoue avec l'erreur donnée.
 */
final class RecordingMailer implements MailerInterface
{
    /** @var list<RawMessage> */
    public array $sent = [];

    public function __construct(private readonly ?\Throwable $failure = null)
    {
    }

    public function send(RawMessage $message, ?Envelope $envelope = null): void
    {
        if (null !== $this->failure) {
            throw $this->failure;
        }
        $this->sent[] = $message;
    }
}
