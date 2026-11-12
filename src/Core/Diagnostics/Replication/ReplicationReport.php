<?php

declare(strict_types=1);

namespace App\Core\Diagnostics\Replication;

use App\Core\Diagnostics\DiagnosticStatus;

/**
 * L'état de la réplication d'un serveur. « Pas de réplication configurée » est un état franc (rôle `none`, source lisible), jamais
 * une erreur ; une source illisible (droit, version) le dit dans `status`, avec un rôle `none` et aucun canal : ce n'est pas la
 * même chose.
 */
final readonly class ReplicationReport
{
    /**
     * @param list<ReplicationChannel> $channels
     */
    public function __construct(
        public DiagnosticStatus $status,
        public ReplicationRole $role,
        public array $channels,
        public ReplicationSettings $settings,
        /** Réplicas connectés à ce serveur (null : le moteur ou le compte ne le dit pas). */
        public ?int $replicaCount,
    ) {
    }

    /**
     * @return list<ReplicationAlert>
     */
    public function alerts(): array
    {
        return ReplicationAlerts::evaluate($this);
    }
}
