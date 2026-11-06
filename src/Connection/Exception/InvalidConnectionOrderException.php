<?php

declare(strict_types=1);

namespace App\Connection\Exception;

/**
 * Un ordre de connexions qui cite une connexion inexistante, répétée ou d'un autre compte. Le message ne dit jamais
 * laquelle : une connexion d'autrui est indiscernable d'une connexion inexistante.
 */
final class InvalidConnectionOrderException extends \DomainException
{
    public static function create(): self
    {
        return new self('The connection order is not valid.');
    }
}
