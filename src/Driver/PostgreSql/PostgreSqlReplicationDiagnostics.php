<?php

declare(strict_types=1);

namespace App\Driver\PostgreSql;

use App\Core\Diagnostics\DiagnosticStatus;
use App\Core\Diagnostics\Replication\ReplicationReport;
use App\Core\Diagnostics\ReplicationDiagnostics;
use App\Driver\DbalCatalogReader;
use Doctrine\DBAL\Connection;

/**
 * Réplication de PostgreSQL : `pg_is_in_recovery()`, `pg_stat_wal_receiver` (réplica), `pg_stat_replication` (source) et les
 * positions WAL reçue / rejouée. Le retard est le temps écoulé depuis le dernier rejeu SEULEMENT quand du WAL reçu n'est pas encore
 * rejoué : sur un primaire au repos, « le dernier rejeu date d'une heure » n'est pas un retard. Sans pg_read_all_stats les colonnes
 * de ces vues sont masquées : état « droits insuffisants ».
 */
final readonly class PostgreSqlReplicationDiagnostics implements ReplicationDiagnostics
{
    private const PRIVILEGE = 'pg_read_all_stats';

    private DbalCatalogReader $catalog;

    public function __construct(Connection $connection)
    {
        $this->catalog = DbalCatalogReader::postgresql($connection);
    }

    public function status(): DiagnosticStatus
    {
        return $this->catalog->read(fn (Connection $c): DiagnosticStatus => $this->canSeeAll($c) ? DiagnosticStatus::available() : DiagnosticStatus::denied(self::PRIVILEGE));
    }

    public function replication(): ReplicationReport
    {
        return $this->catalog->read(function (Connection $c): ReplicationReport {
            if (!$this->canSeeAll($c)) {
                return ReplicationReport::unreadable(DiagnosticStatus::denied(self::PRIVILEGE));
            }

            return PostgreSqlReplicationReport::build($c->fetchAssociative(
                'SELECT pg_is_in_recovery() AS in_recovery,
                        (SELECT status FROM pg_stat_wal_receiver LIMIT 1) AS receiver_status,
                        pg_last_wal_receive_lsn()::text AS received,
                        pg_last_wal_replay_lsn()::text AS applied,
                        CASE WHEN pg_is_in_recovery() THEN pg_is_wal_replay_paused() END AS paused,
                        CASE WHEN pg_last_wal_receive_lsn() IS NOT DISTINCT FROM pg_last_wal_replay_lsn() THEN 0
                             ELSE EXTRACT(EPOCH FROM (now() - pg_last_xact_replay_timestamp()))::bigint END AS lag_seconds,
                        (SELECT count(*) FROM pg_stat_replication) AS replica_count',
            ) ?: throw new \UnexpectedValueException('The replication query returned no row.'));
        });
    }

    private function canSeeAll(Connection $connection): bool
    {
        return (bool) $connection->fetchOne(
            "SELECT pg_has_role(current_user, 'pg_read_all_stats', 'USAGE') OR COALESCE((SELECT rolsuper FROM pg_roles WHERE rolname = current_user), false)",
        );
    }
}
