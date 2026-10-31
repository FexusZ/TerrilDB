<?php

declare(strict_types=1);

namespace App\Entity\Exception;

/**
 * Donnée refusée par l'entité DatabaseConnection : elle garde elle-même ses invariants.
 */
final class InvalidDatabaseConnectionException extends \DomainException
{
}
