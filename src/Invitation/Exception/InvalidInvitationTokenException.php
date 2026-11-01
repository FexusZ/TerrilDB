<?php

declare(strict_types=1);

namespace App\Invitation\Exception;

/**
 * Invitation absente, mal formée, inconnue, utilisée, expirée ou révoquée. Le message ne dit jamais laquelle,
 * ni ne cite le jeton : le serveur ne doit pas aider à deviner une invitation valide.
 */
final class InvalidInvitationTokenException extends \RuntimeException
{
    public static function create(): self
    {
        return new self('The invitation is not valid.');
    }
}
