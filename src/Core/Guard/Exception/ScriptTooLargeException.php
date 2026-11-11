<?php

declare(strict_types=1);

namespace App\Core\Guard\Exception;

final class ScriptTooLargeException extends \RuntimeException
{
    public static function tooLong(int $maxBytes): self
    {
        return new self(\sprintf('The script is longer than %d bytes.', $maxBytes));
    }

    public static function tooManyStatements(int $max): self
    {
        return new self(\sprintf('The script has more than %d statements.', $max));
    }
}
