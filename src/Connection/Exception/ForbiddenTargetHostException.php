<?php

declare(strict_types=1);

namespace App\Connection\Exception;

/**
 * Un membre vise le serveur de TerrilDB lui-même (boucle locale, lien local, base interne) : réservé aux administrateurs.
 * Le message ne dit jamais ce que l'adresse a donné à la résolution.
 */
final class ForbiddenTargetHostException extends \DomainException
{
    public static function create(): self
    {
        return new self('This address belongs to the server itself and is reserved to administrators.');
    }
}
