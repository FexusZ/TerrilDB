<?php

declare(strict_types=1);

namespace App\Security\Exception;

/**
 * Le mot de passe est correct mais le compte exige un second facteur qui n'a pas été fourni.
 */
final class SecondFactorRequiredException extends LoginFailedException
{
    public static function create(): self
    {
        return new self('A second factor is required.');
    }
}
