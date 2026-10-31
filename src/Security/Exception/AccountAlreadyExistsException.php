<?php

declare(strict_types=1);

namespace App\Security\Exception;

/**
 * L'e-mail ou le nom d'utilisateur est déjà pris. Le message ne dit pas lequel : l'inscription ne doit pas
 * servir à sonder quels comptes existent.
 */
final class AccountAlreadyExistsException extends \DomainException
{
    public static function create(?\Throwable $previous = null): self
    {
        return new self('An account with this email address or username already exists.', previous: $previous);
    }
}
