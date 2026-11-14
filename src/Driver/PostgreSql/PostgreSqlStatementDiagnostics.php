<?php

declare(strict_types=1);

namespace App\Driver\PostgreSql;

use App\Core\Diagnostics\DiagnosticStatus;
use App\Core\Diagnostics\DigestOrder;
use App\Core\Diagnostics\DigestQuery;
use App\Core\Diagnostics\DigestReport;
use App\Core\Diagnostics\StatementDiagnostics;
use App\Core\Diagnostics\StatementDigest;
use App\Core\Guard\SqlDialect;
use App\Driver\DbalCatalogReader;
use App\Driver\RowReader;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\DriverException;

/**
 * Requêtes coûteuses de PostgreSQL par empreinte : l'extension pg_stat_statements (texte normalisé, les valeurs y sont des
 * « $1 »). Elle exige `shared_preload_libraries` (redémarrage du serveur) PUIS `CREATE EXTENSION` dans la base : sans l'un
 * ou l'autre l'état est « source éteinte » avec la raison, jamais une liste vide, et TerrilDB n'exécute rien de tout cela.
 * Sans `pg_read_all_stats` le serveur remplace le texte des requêtes des autres par « <insufficient privilege> » : état
 * « droits insuffisants ». Seules les requêtes de la base de la connexion sont listées. L'extension ne compte pas les lignes
 * lues, seulement celles renvoyées : le ratio reste inconnu.
 */
final readonly class PostgreSqlStatementDiagnostics implements StatementDiagnostics
{
    private const PRIVILEGE = 'pg_read_all_stats';
    private const OBJECT_NOT_IN_PREREQUISITE_STATE = '55000';

    private DbalCatalogReader $catalog;

    public function __construct(Connection $connection)
    {
        $this->catalog = DbalCatalogReader::postgresql($connection);
    }

    public function status(): DiagnosticStatus
    {
        return $this->catalog->read(fn (Connection $c): DiagnosticStatus => $this->check($c));
    }

    public function top(DigestQuery $query): DigestReport
    {
        return $this->catalog->read(function (Connection $c) use ($query): DigestReport {
            $status = $this->check($c);

            return new DigestReport($status, $query->order, $status->isAvailable() ? $this->read($c, $query) : []);
        });
    }

    private function check(Connection $connection): DiagnosticStatus
    {
        if (false === $connection->fetchOne("SELECT 1 FROM pg_extension WHERE extname = 'pg_stat_statements'")) {
            return DiagnosticStatus::disabled('extension_missing');
        }
        try {
            $connection->fetchOne('SELECT 1 FROM pg_stat_statements LIMIT 1');
        } catch (DriverException $e) {
            // « doit être chargée par shared_preload_libraries » : le dernier mot revient au serveur.
            if (self::OBJECT_NOT_IN_PREREQUISITE_STATE === $e->getSQLState()) {
                return DiagnosticStatus::disabled('library_not_loaded');
            }

            throw $e;
        }
        $canSeeAll = (bool) $connection->fetchOne(
            "SELECT pg_has_role(current_user, 'pg_read_all_stats', 'USAGE') OR COALESCE((SELECT rolsuper FROM pg_roles WHERE rolname = current_user), false)",
        );

        return $canSeeAll ? DiagnosticStatus::available() : DiagnosticStatus::denied(self::PRIVILEGE);
    }

    /**
     * @return list<StatementDigest>
     */
    private function read(Connection $c, DigestQuery $query): array
    {
        // Le classement vient d'une liste fermée : jamais un nom de colonne reçu tel quel.
        $order = match ($query->order) {
            DigestOrder::TotalTime => 's.total_exec_time',
            DigestOrder::MeanTime => 's.mean_exec_time',
            DigestOrder::Calls => 's.calls',
        };
        // stats_since (PostgreSQL 17 et après) dit depuis quand l'empreinte est comptée.
        $since = $c->fetchOne("SELECT 1 FROM pg_attribute a JOIN pg_class c ON c.oid = a.attrelid WHERE c.relname = 'pg_stat_statements' AND a.attname = 'stats_since' AND NOT a.attisdropped");
        $firstSeen = false === $since ? 'NULL' : "to_char(s.stats_since AT TIME ZONE 'UTC', 'YYYY-MM-DD\"T\"HH24:MI:SS\"Z\"')";
        $rows = $c->fetchAllAssociative(
            'SELECT LEFT(s.query, 4096) AS text, d.datname AS db, s.calls, s.total_exec_time AS total_ms, s.mean_exec_time AS mean_ms,
                    s.max_exec_time AS max_ms, s.rows AS returned, '.$firstSeen.' AS first_seen
               FROM pg_stat_statements s
               JOIN pg_database d ON d.oid = s.dbid AND d.datname = current_database()
              WHERE s.calls > 0
              ORDER BY '.$order.' DESC, s.queryid
              LIMIT '.$query->limit,
        );

        $digests = [];
        foreach ($rows as $row) {
            $row = new RowReader($row);
            $digests[] = new StatementDigest(
                $row->nullableString('text'),
                $row->nullableString('db'),
                $row->int('calls'),
                $row->float('total_ms'),
                $row->float('mean_ms'),
                $row->nullableFloat('max_ms'),
                null,
                $row->int('returned'),
                null,
                null,
                null,
                $row->nullableString('first_seen'),
                null,
                SqlDialect::PostgreSql,
            );
        }

        return $digests;
    }
}
