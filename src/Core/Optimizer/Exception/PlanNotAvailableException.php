<?php

declare(strict_types=1);

namespace App\Core\Optimizer\Exception;

use App\Core\Connection\Engine;

/**
 * Ce moteur n'offre pas (encore) de plan d'exécution lisible par TerrilDB.
 */
final class PlanNotAvailableException extends \DomainException
{
    public static function for(Engine $engine): self
    {
        return new self(\sprintf('No execution plan is available for "%s".', $engine->value));
    }
}
