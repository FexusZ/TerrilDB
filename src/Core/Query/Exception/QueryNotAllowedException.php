<?php

declare(strict_types=1);

namespace App\Core\Query\Exception;

use App\Core\Guard\GuardReason;

/**
 * L'instruction n'est pas partie vers la cible. Le motif est un cas stable, pas un texte : l'interface,
 * l'API et le MCP en tirent chacun leur message.
 */
abstract class QueryNotAllowedException extends \DomainException
{
    public function __construct(public readonly GuardReason $reason)
    {
        parent::__construct(\sprintf('The statement was not run (%s).', $reason->value));
    }
}
