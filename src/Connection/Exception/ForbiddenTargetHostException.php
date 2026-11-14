<?php

declare(strict_types=1);

namespace App\Connection\Exception;

/**
 * Un membre vise le serveur de TerrilDB lui-même (boucle locale, lien local, base interne) : réservé aux administrateurs.
 * Le message ne dit jamais ce que l'adresse a donné à la résolution.
 */
final class ForbiddenTargetHostException extends \DomainException
{
    private function __construct(public readonly bool $behindJump)
    {
        parent::__construct('This address belongs to the server itself and is reserved to administrators.');
    }

    /**
     * @param bool $behindJump vrai quand c'est la cible finale, derrière un bastion, qui est refusée (et non le bastion)
     */
    public static function create(bool $behindJump = false): self
    {
        return new self($behindJump);
    }
}
