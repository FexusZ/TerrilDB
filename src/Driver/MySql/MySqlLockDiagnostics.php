<?php

declare(strict_types=1);

namespace App\Driver\MySql;

use App\Core\Connection\Engine;
use App\Core\Diagnostics\DiagnosticStatus;
use App\Core\Diagnostics\LockDiagnostics;
use App\Core\Diagnostics\LockKind;
use App\Core\Diagnostics\LockParty;
use App\Core\Diagnostics\LockReport;
use App\Core\Diagnostics\LockWait;
use App\Core\Diagnostics\OpenTransaction;
use App\Core\Diagnostics\TableHolder;
use App\Core\Diagnostics\TableHolders;
use App\Core\Guard\SqlDialect;
use App\Driver\DbalCatalogReader;
use App\Driver\RowReader;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\DriverException;

/**
 * Transactions ouvertes et attentes de verrous de MySQL et de MariaDB.
 *
 * Transactions : information_schema.INNODB_TRX (privilège PROCESS), les deux moteurs.
 * MySQL : attentes de lignes par performance_schema.data_lock_waits / data_locks, attentes de métadonnées (la file derrière
 * un DDL) par la vue sys.schema_table_lock_waits ; sans performance_schema allumé, état « source éteinte » (jamais « aucun verrou »).
 * MariaDB : pas de data_locks, attentes de lignes par INNODB_LOCK_WAITS / INNODB_LOCKS ; ses verrous de métadonnées ne se lisent
 * que par performance_schema, éteint par défaut : ils ne sont pas rapportés (le moteur n'a pas cette source ici).
 */
final readonly class MySqlLockDiagnostics implements LockDiagnostics
{
    private const LIMIT = 200;
    private const ER_TABLEACCESS_DENIED = 1142;
    private const ER_VIEW_INVALID = 1356;
    private const ER_SPECIFIC_ACCESS_DENIED = 1227;

    private DbalCatalogReader $catalog;

    public function __construct(Connection $connection, private Engine $engine)
    {
        $this->catalog = DbalCatalogReader::mysql($connection);
    }

    public function status(): DiagnosticStatus
    {
        return $this->catalog->read(fn (Connection $c): DiagnosticStatus => $this->check($c));
    }

    public function locks(): LockReport
    {
        return $this->catalog->read(function (Connection $c): LockReport {
            $status = $this->check($c);
            if (!$status->isAvailable()) {
                return new LockReport($status, [], [], false);
            }

            try {
                return $this->read($c);
            } catch (DriverException $e) {
                // Un droit retiré entre la vérification et la lecture : l'état le dit, ce n'est pas une panne.
                if (self::isRefusal($e)) {
                    return new LockReport(DiagnosticStatus::denied('SELECT ON performance_schema'), [], [], false);
                }

                throw $e;
            }
        });
    }

    /**
     * Les verrous de métadonnées ACCORDÉS sur la table (même une simple lecture dans une transaction ouverte en tient un) : ceux
     * que le ALTER attendrait. Source : performance_schema.metadata_locks, éteint par défaut sur MariaDB (état « source éteinte »).
     */
    public function tableHolders(string $table): TableHolders
    {
        return $this->catalog->read(static function (Connection $c) use ($table): TableHolders {
            if (1 !== (new RowReader(['v' => $c->fetchOne('SELECT @@performance_schema')]))->int('v')) {
                return TableHolders::unreadable(DiagnosticStatus::disabled('performance_schema_off'));
            }

            try {
                $rows = $c->fetchAllAssociative(
                    "SELECT t.PROCESSLIST_ID AS id, MAX(t.PROCESSLIST_USER) AS usr, MAX(t.PROCESSLIST_COMMAND = 'Sleep') AS sleeping,
                            MAX(TIMESTAMPDIFF(SECOND, x.trx_started, NOW())) AS seconds, MAX(m.LOCK_TYPE) AS mode
                       FROM performance_schema.metadata_locks m
                       JOIN performance_schema.threads t ON t.THREAD_ID = m.OWNER_THREAD_ID
                       LEFT JOIN information_schema.INNODB_TRX x ON x.trx_mysql_thread_id = t.PROCESSLIST_ID
                      WHERE m.OBJECT_TYPE = 'TABLE' AND m.LOCK_STATUS = 'GRANTED'
                        AND m.OBJECT_SCHEMA = DATABASE() AND m.OBJECT_NAME = ?
                        AND t.PROCESSLIST_ID IS NOT NULL AND t.PROCESSLIST_ID <> CONNECTION_ID()
                      GROUP BY t.PROCESSLIST_ID
                      ORDER BY seconds DESC, t.PROCESSLIST_ID
                      LIMIT ".self::LIMIT,
                    [$table],
                );
            } catch (DriverException $e) {
                if (self::isRefusal($e)) {
                    return TableHolders::unreadable(DiagnosticStatus::denied('SELECT ON performance_schema'));
                }

                throw $e;
            }

            $holders = [];
            foreach ($rows as $row) {
                $row = new RowReader($row);
                $holders[] = new TableHolder($row->string('id'), $row->nullableString('usr'), null === $row->nullableInt('seconds') ? null : max(0, $row->int('seconds')), $row->bool('sleeping'), $row->nullableString('mode'));
            }

            return new TableHolders(DiagnosticStatus::available(), $holders);
        });
    }

    private static function isRefusal(DriverException $e): bool
    {
        return \in_array($e->getCode(), [self::ER_TABLEACCESS_DENIED, self::ER_VIEW_INVALID, self::ER_SPECIFIC_ACCESS_DENIED], true);
    }

    private function check(Connection $connection): DiagnosticStatus
    {
        if (!MySqlPrivileges::hasProcess($connection)) {
            return DiagnosticStatus::denied('PROCESS');
        }
        if (Engine::MariaDb === $this->engine) {
            return DiagnosticStatus::available();
        }
        if (1 !== (new RowReader(['v' => $connection->fetchOne('SELECT @@performance_schema')]))->int('v')) {
            return DiagnosticStatus::disabled('performance_schema_off');
        }
        foreach (['SELECT 1 FROM performance_schema.data_lock_waits LIMIT 1' => 'SELECT ON performance_schema', 'SELECT 1 FROM sys.schema_table_lock_waits LIMIT 1' => 'SELECT AND EXECUTE ON sys'] as $probe => $privilege) {
            try {
                $connection->fetchOne($probe);
            } catch (DriverException $e) {
                if (self::isRefusal($e)) {
                    return DiagnosticStatus::denied($privilege);
                }

                throw $e;
            }
        }

        return DiagnosticStatus::available();
    }

    private function read(Connection $c): LockReport
    {
        $rows = $c->fetchAllAssociative(
            "SELECT t.trx_mysql_thread_id AS id, p.USER AS usr, t.trx_state AS state,
                    TIMESTAMPDIFF(SECOND, t.trx_started, NOW()) AS seconds,
                    t.trx_rows_locked AS rows_locked, t.trx_rows_modified AS rows_modified,
                    COALESCE(p.COMMAND = 'Sleep', 0) AS sleeping, LEFT(t.trx_query, 4096) AS query
               FROM information_schema.INNODB_TRX t
               LEFT JOIN information_schema.PROCESSLIST p ON p.ID = t.trx_mysql_thread_id
              WHERE t.trx_mysql_thread_id <> CONNECTION_ID()
              ORDER BY t.trx_started, t.trx_id
              LIMIT ".(self::LIMIT + 1),
        );
        $transactions = [];
        foreach (\array_slice($rows, 0, self::LIMIT) as $row) {
            $row = new RowReader($row);
            $transactions[] = new OpenTransaction($row->string('id'), $row->nullableString('usr'), $row->nullableString('state'), max(0, $row->int('seconds')), $row->nullableInt('rows_locked'), $row->nullableInt('rows_modified'), $row->bool('sleeping'), $row->nullableString('query'), SqlDialect::MySql);
        }

        $waitRows = Engine::MariaDb === $this->engine ? $this->mariaDbRowWaits($c) : $this->mySqlRowWaits($c);
        $metadataRows = Engine::MariaDb === $this->engine ? [] : $this->metadataWaits($c);
        $waits = [];
        foreach (\array_slice($waitRows, 0, self::LIMIT) as $row) {
            $waits[] = self::wait(new RowReader($row), LockKind::Row);
        }
        foreach (\array_slice($metadataRows, 0, self::LIMIT) as $row) {
            $waits[] = self::wait(new RowReader($row), LockKind::Metadata);
        }

        return new LockReport(DiagnosticStatus::available(), $transactions, $waits, \count($rows) > self::LIMIT || \count($waitRows) > self::LIMIT || \count($metadataRows) > self::LIMIT);
    }

    private static function wait(RowReader $row, LockKind $kind): LockWait
    {
        return new LockWait(
            new LockParty($row->string('waiting'), $row->nullableString('waiting_user'), $row->nullableString('waiting_query'), SqlDialect::MySql),
            new LockParty($row->string('blocking'), $row->nullableString('blocking_user'), $row->nullableString('blocking_query'), SqlDialect::MySql),
            null === $row->nullableInt('seconds') ? null : max(0, $row->int('seconds')),
            $kind,
            $row->nullableString('mode'),
            $row->nullableString('object'),
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function mySqlRowWaits(Connection $c): array
    {
        return $c->fetchAllAssociative(
            "SELECT DISTINCT rt.trx_mysql_thread_id AS waiting, wp.USER AS waiting_user, LEFT(rt.trx_query, 4096) AS waiting_query,
                    bt.trx_mysql_thread_id AS blocking, bp.USER AS blocking_user, LEFT(bt.trx_query, 4096) AS blocking_query,
                    TIMESTAMPDIFF(SECOND, rt.trx_wait_started, NOW()) AS seconds,
                    rl.LOCK_MODE AS mode, CONCAT(rl.OBJECT_SCHEMA, '.', rl.OBJECT_NAME) AS object
               FROM performance_schema.data_lock_waits w
               JOIN information_schema.INNODB_TRX rt ON rt.trx_id = w.REQUESTING_ENGINE_TRANSACTION_ID
               JOIN information_schema.INNODB_TRX bt ON bt.trx_id = w.BLOCKING_ENGINE_TRANSACTION_ID
               LEFT JOIN performance_schema.data_locks rl ON rl.ENGINE_LOCK_ID = w.REQUESTING_ENGINE_LOCK_ID AND rl.ENGINE = 'INNODB'
               LEFT JOIN information_schema.PROCESSLIST wp ON wp.ID = rt.trx_mysql_thread_id
               LEFT JOIN information_schema.PROCESSLIST bp ON bp.ID = bt.trx_mysql_thread_id
              ORDER BY seconds DESC, waiting, blocking
              LIMIT ".(self::LIMIT + 1),
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function mariaDbRowWaits(Connection $c): array
    {
        return $c->fetchAllAssociative(
            "SELECT DISTINCT rt.trx_mysql_thread_id AS waiting, wp.USER AS waiting_user, LEFT(rt.trx_query, 4096) AS waiting_query,
                    bt.trx_mysql_thread_id AS blocking, bp.USER AS blocking_user, LEFT(bt.trx_query, 4096) AS blocking_query,
                    TIMESTAMPDIFF(SECOND, rt.trx_wait_started, NOW()) AS seconds,
                    l.lock_mode AS mode, REPLACE(l.lock_table, '`', '') AS object
               FROM information_schema.INNODB_LOCK_WAITS w
               JOIN information_schema.INNODB_TRX rt ON rt.trx_id = w.requesting_trx_id
               JOIN information_schema.INNODB_TRX bt ON bt.trx_id = w.blocking_trx_id
               LEFT JOIN information_schema.INNODB_LOCKS l ON l.lock_id = w.requested_lock_id
               LEFT JOIN information_schema.PROCESSLIST wp ON wp.ID = rt.trx_mysql_thread_id
               LEFT JOIN information_schema.PROCESSLIST bp ON bp.ID = bt.trx_mysql_thread_id
              ORDER BY seconds DESC, waiting, blocking
              LIMIT ".(self::LIMIT + 1),
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function metadataWaits(Connection $c): array
    {
        return $c->fetchAllAssociative(
            "SELECT waiting_pid AS waiting, SUBSTRING_INDEX(waiting_account, '@', 1) AS waiting_user, LEFT(waiting_query, 4096) AS waiting_query,
                    blocking_pid AS blocking, SUBSTRING_INDEX(blocking_account, '@', 1) AS blocking_user, NULL AS blocking_query,
                    waiting_query_secs AS seconds, waiting_lock_type AS mode, CONCAT(object_schema, '.', object_name) AS object
               FROM sys.schema_table_lock_waits
              ORDER BY waiting_query_secs DESC, waiting_pid, blocking_pid
              LIMIT ".(self::LIMIT + 1),
        );
    }
}
