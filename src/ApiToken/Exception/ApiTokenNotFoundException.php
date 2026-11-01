<?php

declare(strict_types=1);

namespace App\ApiToken\Exception;

/**
 * Le jeton n'existe pas ou n'appartient pas à l'utilisateur : indiscernables, comme pour les connexions.
 */
final class ApiTokenNotFoundException extends \DomainException
{
    public static function create(): self
    {
        return new self('API token not found.');
    }
}
