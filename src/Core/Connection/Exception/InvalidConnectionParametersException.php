<?php

declare(strict_types=1);

namespace App\Core\Connection\Exception;

/**
 * Paramètres de connexion refusés. Le message ne cite jamais le mot de passe.
 */
final class InvalidConnectionParametersException extends \DomainException
{
}
