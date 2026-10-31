<?php

declare(strict_types=1);

namespace App\Connection\Exception;

final class ConnectionNameAlreadyUsedException extends \DomainException
{
    public static function create(?\Throwable $previous = null): self
    {
        return new self('You already have a connection with this name.', previous: $previous);
    }
}
