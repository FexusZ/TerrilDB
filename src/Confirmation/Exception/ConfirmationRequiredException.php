<?php

declare(strict_types=1);

namespace App\Confirmation\Exception;

/**
 * Ce jeton d'API écrit en deux étapes : il faut préparer l'écriture (`prepare`), puis l'exécuter avec le jeton de confirmation reçu.
 */
final class ConfirmationRequiredException extends \DomainException
{
    public static function create(): self
    {
        return new self('A confirmation token from a preparation is required.');
    }
}
