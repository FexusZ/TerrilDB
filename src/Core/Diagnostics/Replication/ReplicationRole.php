<?php

declare(strict_types=1);

namespace App\Core\Diagnostics\Replication;

/**
 * Le rôle d'un serveur dans la réplication. Un réplica qui a lui-même des réplicas reste un réplica : le nombre de ses
 * réplicas le dit à part.
 */
enum ReplicationRole: string
{
    /** Aucune réplication configurée : ni réplica, ni source avec des réplicas connectés. */
    case None = 'none';
    case Source = 'source';
    case Replica = 'replica';
}
