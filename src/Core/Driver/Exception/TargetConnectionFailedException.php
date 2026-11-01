<?php

declare(strict_types=1);

namespace App\Core\Driver\Exception;

/**
 * La base cible n'a pas pu être jointe (injoignable, identifiants refusés, base inconnue…). Le message est
 * volontairement générique : le motif détaillé du moteur reste dans l'exception précédente, pour les journaux,
 * et ne doit jamais être montré tel quel à un utilisateur.
 */
final class TargetConnectionFailedException extends \RuntimeException
{
    public static function create(?\Throwable $previous = null): self
    {
        return new self('Could not connect to the database.', previous: $previous);
    }
}
