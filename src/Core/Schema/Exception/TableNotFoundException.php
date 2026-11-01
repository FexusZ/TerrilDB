<?php

declare(strict_types=1);

namespace App\Core\Schema\Exception;

final class TableNotFoundException extends \DomainException
{
    public static function named(string $name): self
    {
        return new self(\sprintf('Table "%s" not found.', $name));
    }
}
