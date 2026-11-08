<?php

declare(strict_types=1);

namespace App\Core\Data\Exception;

use App\Core\Data\DataPlan;

/**
 * Le plan supprime des lignes : l'interface demande une confirmation avant de réessayer avec `confirmed`.
 */
final class DataChangeNeedsConfirmationException extends \RuntimeException
{
    public function __construct(public readonly DataPlan $plan)
    {
        parent::__construct('Deleting rows needs a confirmation.');
    }
}
