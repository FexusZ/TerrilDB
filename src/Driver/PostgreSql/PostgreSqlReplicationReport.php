<?php

declare(strict_types=1);

namespace App\Driver\PostgreSql;

use App\Core\Diagnostics\DiagnosticStatus;
use App\Core\Diagnostics\Replication\ReplicationChannel;
use App\Core\Diagnostics\Replication\ReplicationReport;
use App\Core\Diagnostics\Replication\ReplicationRole;
use App\Core\Diagnostics\Replication\ReplicationSettings;
use App\Core\Diagnostics\Replication\ThreadState;
use App\Driver\RowReader;

/**
 * Assemble, sans accès au serveur, la ligne que relève PostgreSqlReplicationDiagnostics : un réplica (en restauration) a un
 * receveur de WAL (statut de `pg_stat_wal_receiver`, absent quand il n'est pas connecté) et un rejeu (en pause ou non) ; un primaire
 * qui voit des clients de réplication (`pg_stat_replication`) est une source. L'hôte de la source et la chaîne de connexion ne
 * sont jamais lus. PostgreSQL ne garde pas de « dernière erreur » de réplication : aucune n'est rapportée.
 */
final class PostgreSqlReplicationReport
{
    /**
     * @param array<string, mixed> $row
     */
    public static function build(array $row): ReplicationReport
    {
        $row = new RowReader($row);
        $replicaCount = $row->nullableInt('replica_count');

        if (!$row->bool('in_recovery')) {
            return new ReplicationReport(
                DiagnosticStatus::available(),
                null !== $replicaCount && $replicaCount > 0 ? ReplicationRole::Source : ReplicationRole::None,
                [],
                new ReplicationSettings(null, null, null, null),
                $replicaCount,
            );
        }

        $received = $row->nullableString('received');
        $applied = $row->nullableString('applied');
        $channel = new ReplicationChannel(
            '',
            match ($row->nullableString('receiver_status')) {
                'streaming', 'catchup' => ThreadState::Running,
                'starting', 'startup', 'waiting', 'restarting' => ThreadState::Connecting,
                default => ThreadState::Stopped,
            },
            true === $row->bool('paused') ? ThreadState::Stopped : ThreadState::Running,
            $row->nullableInt('lag_seconds'),
            $received,
            $applied,
            null === $received || null === $applied ? null : $received !== $applied,
            null,
            null,
        );

        // Un réplica de PostgreSQL refuse toujours les écritures (hot standby) : pas de réglage à vérifier.
        return new ReplicationReport(DiagnosticStatus::available(), ReplicationRole::Replica, [$channel], new ReplicationSettings(true, null, null, null), $replicaCount);
    }
}
