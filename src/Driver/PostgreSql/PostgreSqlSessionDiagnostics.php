<?php

declare(strict_types=1);

namespace App\Driver\PostgreSql;

use App\Core\Diagnostics\DiagnosticStatus;
use App\Core\Diagnostics\ServerSession;
use App\Core\Diagnostics\SessionDiagnostics;
use App\Core\Diagnostics\SessionReport;
use App\Driver\DbalCatalogReader;
use App\Driver\RowReader;
use Doctrine\DBAL\Connection;

/**
 * Sessions de PostgreSQL par pg_stat_activity (connexions de clients seulement). Sans pg_read_all_stats (ou pg_monitor, ou
 * superutilisateur) le serveur masque l'état et le texte des autres sessions : on rend l'état « droits insuffisants ».
 */
final readonly class PostgreSqlSessionDiagnostics implements SessionDiagnostics
{
    private const LIMIT = 200;
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

    public function sessions(): SessionReport
    {
        return $this->catalog->read(function (Connection $c): SessionReport {
            if (!$this->canSeeAll($c)) {
                return new SessionReport(DiagnosticStatus::denied(self::PRIVILEGE), [], false);
            }

            $rows = $c->fetchAllAssociative(
                "SELECT pid::text AS id, usename AS usr, client_addr::text AS client, datname AS db, state,
                        EXTRACT(EPOCH FROM (now() - COALESCE(state_change, backend_start)))::bigint AS seconds,
                        LEFT(query, 256) AS query, (pid = pg_backend_pid()) AS me
                   FROM pg_stat_activity
                  WHERE backend_type = 'client backend'
                  ORDER BY pid
                  LIMIT ".(self::LIMIT + 1),
            );

            $sessions = [];
            foreach (\array_slice($rows, 0, self::LIMIT) as $row) {
                $row = new RowReader($row);
                $sessions[] = new ServerSession(
                    $row->string('id'),
                    $row->nullableString('usr'),
                    $row->nullableString('client'),
                    $row->nullableString('db'),
                    $row->nullableString('state'),
                    $row->nullableInt('seconds'),
                    $row->nullableString('query'),
                    $row->bool('me'),
                );
            }

            return new SessionReport(DiagnosticStatus::available(), $sessions, \count($rows) > self::LIMIT);
        });
    }

    private function canSeeAll(Connection $connection): bool
    {
        return (bool) $connection->fetchOne(
            "SELECT pg_has_role(current_user, 'pg_read_all_stats', 'USAGE') OR COALESCE((SELECT rolsuper FROM pg_roles WHERE rolname = current_user), false)",
        );
    }
}
