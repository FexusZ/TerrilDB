<?php

declare(strict_types=1);

namespace App\Invitation\Exception;

/**
 * L'envoi par e-mail n'est pas configuré (expéditeur et adresse publique de l'installation).
 */
final class MailDisabledException extends \DomainException
{
    public static function create(): self
    {
        return new self('Sending invitations by e-mail is not configured.');
    }
}
