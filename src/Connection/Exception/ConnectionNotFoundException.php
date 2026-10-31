<?php

declare(strict_types=1);

namespace App\Connection\Exception;

/**
 * La connexion n'existe pas ou n'appartient pas à l'utilisateur : les deux cas sont volontairement
 * indiscernables, pour ne pas révéler l'existence de connexions d'autres comptes.
 */
final class ConnectionNotFoundException extends \DomainException
{
    public static function create(): self
    {
        return new self('Connection not found.');
    }
}
