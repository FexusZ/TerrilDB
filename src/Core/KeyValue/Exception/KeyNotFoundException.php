<?php

declare(strict_types=1);

namespace App\Core\KeyValue\Exception;

final class KeyNotFoundException extends \DomainException
{
    public static function named(string $name): self
    {
        return new self(\sprintf('Key "%s" not found.', $name));
    }
}
