<?php

declare(strict_types=1);

namespace App\Entity\Exception;

/**
 * Donnée ou transition refusée par l'entité User : elle garde elle-même ses invariants.
 */
final class InvalidUserException extends \DomainException
{
}
