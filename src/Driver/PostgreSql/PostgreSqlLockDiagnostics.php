<?php

declare(strict_types=1);

namespace App\Driver\PostgreSql;

use App\Core\Diagnostics\DiagnosticStatus;
use App\Core\Diagnostics\LockDiagnostics;
use App\Core\Diagnostics\LockKind;
use App\Core\Diagnostics\LockParty;
use App\Core\Diagnostics\LockReport;
use App\Core\Diagnostics\LockWait;
use App\Core\Diagnostics\OpenTransaction;
use App\Driver\DbalCatalogReader;
use App\Driver\RowReader;
use Doctrine\DBAL\Connection;

/**
 * Transactions ouvertes (pg_stat_activity) et attentes de verrous (pg_blocking_pids, que le serveur calcule lui-même) de
 * PostgreSQL. Sans pg_read_all_stats le serveur masque l'état et le texte des autres sessions : état « droits insuffisants ».
 */
final readonly class PostgreSqlLockDiagnostics implements LockDiagnostics
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

    public function locks(): LockReport
    {
        return $this->catalog->read(function (Connection $c): LockReport {
            if (!$this->canSeeAll($c)) {
                return new LockReport(DiagnosticStatus::denied(self::PRIVILEGE), [], [], false);
            }

            $rows = $c->fetchAllAssociative(
                "SELECT pid::text AS id, usename AS usr, state,
                        EXTRACT(EPOCH FROM (now() - xact_start))::bigint AS seconds,
                        (state IN ('idle in transaction', 'idle in transaction (aborted)')) AS sleeping,
                        LEFT(query, 256) AS query
                   FROM pg_stat_activity
                  WHERE backend_type = 'client backend' AND xact_start IS NOT NULL AND pid <> pg_backend_pid()
                  ORDER BY xact_start, pid
                  LIMIT ".(self::LIMIT + 1),
            );
            $transactions = [];
            foreach (\array_slice($rows, 0, self::LIMIT) as $row) {
                $row = new RowReader($row);
                $transactions[] = new OpenTransaction($row->string('id'), $row->nullableString('usr'), $row->nullableString('state'), max(0, $row->int('seconds')), null, null, $row->bool('sleeping'), $row->nullableString('query'));
            }

            // L'objet attendu : la relation du verrou en attente, sinon la table que l'attendant et le bloquant tiennent tous deux
            // (une attente de ligne se fait sur un identifiant de transaction, qui ne nomme pas de table).
            $waitRows = $c->fetchAllAssociative(
                "SELECT a.pid::text AS waiting, a.usename AS waiting_user, LEFT(a.query, 256) AS waiting_query,
                        b.pid::text AS blocking, b.usename AS blocking_user, LEFT(b.query, 256) AS blocking_query,
                        EXTRACT(EPOCH FROM (now() - COALESCE(a.state_change, a.query_start)))::bigint AS seconds,
                        l.mode, COALESCE(l.rel::regclass::text, l.locktype) AS object
                   FROM pg_stat_activity a
                  CROSS JOIN LATERAL unnest(pg_blocking_pids(a.pid)) AS bp(pid)
                   JOIN pg_stat_activity b ON b.pid = bp.pid
                   LEFT JOIN LATERAL (
                        SELECT w.mode, w.locktype,
                               COALESCE(w.relation, (
                                   SELECT lw.relation FROM pg_locks lw
                                     JOIN pg_locks lb ON lb.relation = lw.relation AND lb.pid = b.pid AND lb.locktype = 'relation'
                                     JOIN pg_class c ON c.oid = lw.relation AND c.relkind IN ('r', 'p', 'm')
                                    WHERE lw.pid = a.pid AND lw.locktype = 'relation' LIMIT 1)) AS rel
                          FROM pg_locks w WHERE w.pid = a.pid AND NOT w.granted LIMIT 1) l ON true
                  WHERE a.backend_type = 'client backend'
                  ORDER BY seconds DESC, a.pid, b.pid
                  LIMIT ".(self::LIMIT + 1),
            );
            $waits = [];
            foreach (\array_slice($waitRows, 0, self::LIMIT) as $row) {
                $row = new RowReader($row);
                $waits[] = new LockWait(
                    new LockParty($row->string('waiting'), $row->nullableString('waiting_user'), $row->nullableString('waiting_query')),
                    new LockParty($row->string('blocking'), $row->nullableString('blocking_user'), $row->nullableString('blocking_query')),
                    max(0, $row->int('seconds')),
                    LockKind::Row,
                    $row->nullableString('mode'),
                    $row->nullableString('object'),
                );
            }

            return new LockReport(DiagnosticStatus::available(), $transactions, $waits, \count($rows) > self::LIMIT || \count($waitRows) > self::LIMIT);
        });
    }

    private function canSeeAll(Connection $connection): bool
    {
        return (bool) $connection->fetchOne(
            "SELECT pg_has_role(current_user, 'pg_read_all_stats', 'USAGE') OR COALESCE((SELECT rolsuper FROM pg_roles WHERE rolname = current_user), false)",
        );
    }
}
