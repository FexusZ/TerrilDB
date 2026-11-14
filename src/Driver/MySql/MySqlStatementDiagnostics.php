<?php

declare(strict_types=1);

namespace App\Driver\MySql;

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
 * Requêtes coûteuses de MySQL et de MariaDB par empreinte : performance_schema.events_statements_summary_by_digest (le texte
 * est déjà normalisé par le serveur, les valeurs y sont des « ? »). Les durées de performance_schema sont en PICOSECONDES :
 * elles sont converties en millisecondes ici et nulle part ailleurs. Sans performance_schema allumé (MariaDB le livre éteint)
 * l'état est « source éteinte », jamais une liste vide. La remise à zéro de la fenêtre (TRUNCATE) est une écriture : jamais faite.
 */
final readonly class MySqlStatementDiagnostics implements StatementDiagnostics
{
    private const PICOSECONDS_PER_MILLISECOND = 1_000_000_000;
    private const ER_TABLEACCESS_DENIED = 1142;
    private const ER_SPECIFIC_ACCESS_DENIED = 1227;
    private const PRIVILEGE = 'SELECT ON performance_schema';

    private DbalCatalogReader $catalog;

    public function __construct(Connection $connection)
    {
        $this->catalog = DbalCatalogReader::mysql($connection);
    }

    public function status(): DiagnosticStatus
    {
        return $this->catalog->read(fn (Connection $c): DiagnosticStatus => $this->check($c));
    }

    public function top(DigestQuery $query): DigestReport
    {
        return $this->catalog->read(function (Connection $c) use ($query): DigestReport {
            $status = $this->check($c);
            if (!$status->isAvailable()) {
                return new DigestReport($status, $query->order, []);
            }

            try {
                return new DigestReport($status, $query->order, $this->read($c, $query));
            } catch (DriverException $e) {
                // Un droit retiré entre la vérification et la lecture : l'état le dit, ce n'est pas une panne.
                if (self::isRefusal($e)) {
                    return new DigestReport(DiagnosticStatus::denied(self::PRIVILEGE), $query->order, []);
                }

                throw $e;
            }
        });
    }

    private static function isRefusal(DriverException $e): bool
    {
        return \in_array($e->getCode(), [self::ER_TABLEACCESS_DENIED, self::ER_SPECIFIC_ACCESS_DENIED], true);
    }

    private function check(Connection $connection): DiagnosticStatus
    {
        if (1 !== (new RowReader(['v' => $connection->fetchOne('SELECT @@performance_schema')]))->int('v')) {
            return DiagnosticStatus::disabled('performance_schema_off');
        }
        try {
            $connection->fetchOne('SELECT 1 FROM performance_schema.events_statements_summary_by_digest LIMIT 1');
        } catch (DriverException $e) {
            if (self::isRefusal($e)) {
                return DiagnosticStatus::denied(self::PRIVILEGE);
            }

            throw $e;
        }

        return DiagnosticStatus::available();
    }

    /**
     * @return list<StatementDigest>
     */
    private function read(Connection $c, DigestQuery $query): array
    {
        // Le classement vient d'une liste fermée : jamais un nom de colonne reçu tel quel.
        $order = match ($query->order) {
            DigestOrder::TotalTime => 'SUM_TIMER_WAIT',
            DigestOrder::MeanTime => 'AVG_TIMER_WAIT',
            DigestOrder::Calls => 'COUNT_STAR',
        };
        $rows = $c->fetchAllAssociative(
            'SELECT LEFT(DIGEST_TEXT, 4096) AS text, SCHEMA_NAME AS db, COUNT_STAR AS calls,
                    SUM_TIMER_WAIT AS total_ps, AVG_TIMER_WAIT AS mean_ps, MAX_TIMER_WAIT AS max_ps,
                    SUM_ROWS_EXAMINED AS examined, SUM_ROWS_SENT AS sent, SUM_NO_INDEX_USED AS no_index,
                    SUM_CREATED_TMP_DISK_TABLES AS tmp_disk, SUM_SORT_MERGE_PASSES AS merge_passes,
                    DATE_FORMAT(CONVERT_TZ(FIRST_SEEN, @@session.time_zone, \'+00:00\'), \'%Y-%m-%dT%H:%i:%sZ\') AS first_seen,
                    DATE_FORMAT(CONVERT_TZ(LAST_SEEN, @@session.time_zone, \'+00:00\'), \'%Y-%m-%dT%H:%i:%sZ\') AS last_seen
               FROM performance_schema.events_statements_summary_by_digest
              WHERE DIGEST IS NOT NULL AND COUNT_STAR > 0
              ORDER BY '.$order.' DESC, DIGEST
              LIMIT '.$query->limit,
        );

        $digests = [];
        foreach ($rows as $row) {
            $row = new RowReader($row);
            $digests[] = new StatementDigest(
                $row->nullableString('text'),
                $row->nullableString('db'),
                $row->int('calls'),
                $row->int('total_ps') / self::PICOSECONDS_PER_MILLISECOND,
                $row->int('mean_ps') / self::PICOSECONDS_PER_MILLISECOND,
                $row->int('max_ps') / self::PICOSECONDS_PER_MILLISECOND,
                $row->int('examined'),
                $row->int('sent'),
                $row->int('no_index'),
                $row->int('tmp_disk'),
                $row->int('merge_passes'),
                $row->nullableString('first_seen'),
                $row->nullableString('last_seen'),
                SqlDialect::MySql,
            );
        }

        return $digests;
    }
}
