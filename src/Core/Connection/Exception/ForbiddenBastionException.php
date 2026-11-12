<?php

declare(strict_types=1);

namespace App\Core\Connection\Exception;

/**
 * Le bastion demandé n'est pas dans la liste de l'administrateur (vide par défaut : tout est refusé). Le message ne dit
 * ni ce que la liste contient ni pourquoi.
 */
final class ForbiddenBastionException extends \DomainException
{
    public static function create(): self
    {
        return new self('This jump host is not allowed.');
    }
}
