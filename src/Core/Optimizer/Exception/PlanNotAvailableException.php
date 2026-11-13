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

    /**
     * Le moteur sait expliquer d'autres instructions, mais pas celle-ci (MySQL 9+ en JSON v2 pour UPDATE et DELETE).
     */
    public static function forStatement(Engine $engine): self
    {
        return new self(\sprintf('The engine "%s" gives no execution plan for this statement.', $engine->value));
    }
}
