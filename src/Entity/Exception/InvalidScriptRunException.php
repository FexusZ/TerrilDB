<?php

declare(strict_types=1);

namespace App\Entity\Exception;

/**
 * Transition ou donnée refusée par l'entité ScriptRun : elle garde elle-même ses invariants.
 */
final class InvalidScriptRunException extends \DomainException
{
}
