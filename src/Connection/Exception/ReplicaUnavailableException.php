<?php

declare(strict_types=1);

namespace App\Connection\Exception;

use App\Core\Diagnostics\Vitess\ReplicaAvailability;

/**
 * « Lire sur les réplicas » demandé pour un serveur qui n'a rien à lire : ce n'est pas un vtgate (`not_vitess`) ou il ne connaît
 * aucun réplica en service pour le keyspace (`no_replica`). Refusé à l'enregistrement et à l'essai plutôt qu'à chaque ouverture.
 */
final class ReplicaUnavailableException extends \DomainException
{
    private function __construct(public readonly string $reason)
    {
        parent::__construct('Reading on replicas is not available on this server.');
    }

    public static function for(ReplicaAvailability $availability): self
    {
        return new self(ReplicaAvailability::NotVitess === $availability ? 'not_vitess' : 'no_replica');
    }
}
