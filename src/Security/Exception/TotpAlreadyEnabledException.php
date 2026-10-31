<?php

declare(strict_types=1);

namespace App\Security\Exception;

/**
 * Le compte a déjà un second facteur : il faut le désactiver (mot de passe requis) avant d'en enrôler un autre.
 */
final class TotpAlreadyEnabledException extends \DomainException
{
    public static function create(): self
    {
        return new self('TOTP is already enabled for this account.');
    }
}
