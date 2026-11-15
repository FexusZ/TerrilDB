<?php

declare(strict_types=1);

namespace App\Core\Schema\Change\Exception;

use App\Core\Schema\Change\BigTableAssessment;

/**
 * La table dépasse le seuil de lignes estimées au-delà duquel il faut retaper son nom : rien n'est exécuté sans le nom exact.
 */
final class BigTableNeedsConfirmationException extends \RuntimeException
{
    public function __construct(public readonly BigTableAssessment $assessment)
    {
        parent::__construct('The table is large: its name must be typed to confirm the change.');
    }
}
