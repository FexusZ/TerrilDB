<?php

declare(strict_types=1);

namespace App\ApiToken\Exception;

/**
 * Jeton absent, mal formé, inconnu, expiré ou révoqué. Le message ne dit jamais lequel, ni ne cite le jeton :
 * le serveur ne doit pas aider à deviner un jeton valide.
 */
final class InvalidApiTokenException extends \RuntimeException
{
    public static function create(): self
    {
        return new self('The API token is not valid.');
    }
}
