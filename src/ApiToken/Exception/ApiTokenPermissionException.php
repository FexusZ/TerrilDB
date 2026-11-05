<?php

declare(strict_types=1);

namespace App\ApiToken\Exception;

/**
 * Le jeton est valide mais n'a pas reçu le droit nécessaire à cette opération (écrire, créer des connexions).
 */
final class ApiTokenPermissionException extends \DomainException
{
    public static function create(): self
    {
        return new self('The API token does not hold the right for this operation.');
    }
}
