<?php

declare(strict_types=1);

namespace App\Security\Exception;

/**
 * Code TOTP ou code de récupération faux, expiré ou déjà utilisé.
 */
final class InvalidSecondFactorException extends LoginFailedException
{
    public static function create(): self
    {
        return new self('Invalid second factor.');
    }
}
