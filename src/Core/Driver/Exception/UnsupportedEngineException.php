<?php

declare(strict_types=1);

namespace App\Core\Driver\Exception;

use App\Core\Connection\Engine;

final class UnsupportedEngineException extends \DomainException
{
    public static function for(Engine $engine): self
    {
        return new self(\sprintf('No driver is available for "%s".', $engine->value));
    }
}
