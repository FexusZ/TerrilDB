<?php

declare(strict_types=1);

namespace App\ApiToken\Exception;

/**
 * Révoquer le jeton d'un AUTRE administrateur exige une confirmation explicite : rien n'a été supprimé.
 */
final class AdministratorTokenNeedsConfirmationException extends \DomainException
{
    public const REASON = 'other_administrator_token';

    public static function create(): self
    {
        return new self('Revoking another administrator\'s token needs an explicit confirmation.');
    }
}
