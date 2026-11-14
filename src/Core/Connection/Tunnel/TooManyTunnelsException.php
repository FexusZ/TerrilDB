<?php

declare(strict_types=1);

namespace App\Core\Connection\Tunnel;

/**
 * Le compte tient déjà son nombre maximal de tunnels ouverts : les autres comptes gardent leur place.
 */
final class TooManyTunnelsException extends \RuntimeException
{
    public static function create(): self
    {
        return new self('Too many tunnels are open for this account.');
    }
}
