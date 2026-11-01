<?php

declare(strict_types=1);

namespace App\Core\Driver\Exception;

use App\Core\Connection\Engine;

/**
 * Le moteur n'a pas cette notion (un schéma de tables pour Redis, une navigation par clés pour MySQL…).
 */
final class UnsupportedOperationException extends \DomainException
{
    public static function for(Engine $engine, string $operation): self
    {
        return new self(\sprintf('"%s" is not available for "%s".', $operation, $engine->value));
    }
}
