<?php

declare(strict_types=1);

namespace App\Security\Exception;

/**
 * Phrase de récupération absente, mal formée ou fausse, ou compte sans phrase. Le message ne dit jamais lequel :
 * il ne doit pas aider à deviner une phrase ni à savoir si un compte en possède une.
 */
final class InvalidRecoveryException extends \RuntimeException
{
    public static function create(?\Throwable $previous = null): self
    {
        return new self('The recovery phrase is not valid.', previous: $previous);
    }
}
