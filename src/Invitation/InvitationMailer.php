<?php

declare(strict_types=1);

namespace App\Invitation;

use App\Entity\Exception\InvalidInvitationException;
use App\Invitation\Exception\MailDisabledException;
use App\Invitation\Exception\MailNotSentException;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

/**
 * Envoi facultatif d'une invitation par e-mail. Il n'existe que si l'installation a un expéditeur et une adresse
 * publique : le lien ne se déduit jamais de l'en-tête Host de la requête, que le client contrôle. L'adresse du
 * destinataire sert à envoyer et n'est conservée nulle part (ni base, ni journal d'audit).
 */
final readonly class InvitationMailer
{
    public function __construct(
        private MailerInterface $mailer,
        private string $from,
        private string $publicUrl,
    ) {
    }

    public function isEnabled(): bool
    {
        return '' !== $this->from && '' !== $this->publicUrl;
    }

    public function link(#[\SensitiveParameter] string $token): string
    {
        return rtrim($this->publicUrl, '/').'/app/register?invitation='.$token;
    }

    /**
     * À appeler avant d'émettre l'invitation : une adresse refusée ne doit pas laisser une invitation orpheline.
     */
    public function recipient(string $to): Address
    {
        if (!$this->isEnabled()) {
            throw MailDisabledException::create();
        }

        try {
            return new Address(trim($to));
        } catch (\InvalidArgumentException) {
            throw new InvalidInvitationException('The e-mail address is not valid.');
        }
    }

    public function send(Address $recipient, #[\SensitiveParameter] string $token, \DateTimeImmutable $expiresAt, string $invitedBy): void
    {
        if (!$this->isEnabled()) {
            throw MailDisabledException::create();
        }

        $mail = (new Email())
            ->from($this->from)
            ->to($recipient)
            ->subject('Invitation à rejoindre TerrilDB')
            ->text(\sprintf(
                "%s vous invite à créer un compte sur TerrilDB.\n\nOuvrez ce lien pour choisir votre mot de passe :\n%s\n\nLe lien ne sert qu'une fois et expire le %s.\nVotre mot de passe sert aussi à chiffrer vos connexions : s'il est perdu, elles ne peuvent pas être récupérées.\n\nSi vous n'attendiez pas ce message, ignorez-le.\n",
                $invitedBy,
                $this->link($token),
                $expiresAt->setTimezone(new \DateTimeZone('UTC'))->format('d/m/Y'),
            ));

        try {
            $this->mailer->send($mail);
        } catch (TransportExceptionInterface $failure) {
            throw MailNotSentException::create($failure);
        }
    }
}
