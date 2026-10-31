<?php

declare(strict_types=1);

namespace App\Security\Exception;

/**
 * Login inconnu ou mot de passe faux : volontairement indiscernables.
 */
final class InvalidCredentialsException extends LoginFailedException
{
    public static function create(): self
    {
        return new self('Invalid credentials.');
    }
}
