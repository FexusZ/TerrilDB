<?php

declare(strict_types=1);

namespace App\Invitation\Exception;

/**
 * L'invitation n'existe pas ou n'a pas été émise par cet administrateur : indiscernables.
 */
final class InvitationNotFoundException extends \DomainException
{
    public static function create(): self
    {
        return new self('Invitation not found.');
    }
}
