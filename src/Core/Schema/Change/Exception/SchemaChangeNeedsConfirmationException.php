<?php

declare(strict_types=1);

namespace App\Core\Schema\Change\Exception;

use App\Core\Schema\Change\ChangePlan;

/**
 * Au moins une instruction détruit des données ou une structure : rien n'est exécuté tant que l'utilisateur n'a pas confirmé,
 * en voyant la liste (`plan`).
 */
final class SchemaChangeNeedsConfirmationException extends \RuntimeException
{
    public function __construct(public readonly ChangePlan $plan)
    {
        parent::__construct('The change destroys data or structure and needs a confirmation.');
    }
}
