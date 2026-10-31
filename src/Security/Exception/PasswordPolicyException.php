<?php

declare(strict_types=1);

namespace App\Security\Exception;

/**
 * Mot de passe refusé par la politique. Les messages n'incluent jamais le mot de passe.
 */
final class PasswordPolicyException extends \InvalidArgumentException
{
    public static function tooShort(int $minimum): self
    {
        return new self(\sprintf('The password must be at least %d characters long.', $minimum));
    }

    public static function tooLong(int $maximum): self
    {
        return new self(\sprintf('The password must be at most %d characters long.', $maximum));
    }

    public static function blank(): self
    {
        return new self('The password must not be made of whitespace only.');
    }

    public static function invalidEncoding(): self
    {
        return new self('The password must be valid UTF-8.');
    }
}
