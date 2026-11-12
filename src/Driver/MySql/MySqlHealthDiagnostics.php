<?php

declare(strict_types=1);

namespace App\Driver\MySql;

use App\Core\Diagnostics\DiagnosticStatus;
use App\Core\Diagnostics\Exception\DiagnosticsFailedException;
use App\Core\Diagnostics\Health\HealthSample;
use App\Core\Diagnostics\Health\HealthWindow;
use App\Core\Diagnostics\Health\MySqlHealthIndicators;
use App\Core\Diagnostics\HealthDiagnostics;
use App\Driver\DbalCatalogReader;
use App\Driver\RowReader;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\DriverException;

/**
 * Un relevé de `SHOW GLOBAL STATUS` (liste fixe de compteurs, noms écrits ici, rien de venu du client) et du plafond
 * `max_connections`. MySQL et MariaDB : un compteur qui n'existe pas dans une série est simplement absent du relevé (les
 * indicateurs le disent « inconnu »). Aucun droit particulier n'est attendu ; un refus du serveur est rendu comme tel.
 */
final readonly class MySqlHealthDiagnostics implements HealthDiagnostics
{
    private const ER_TABLEACCESS_DENIED = 1142;
    private const ER_SPECIFIC_ACCESS_DENIED = 1227;
    private const ER_DBACCESS_DENIED = 1044;

    private const COUNTERS = [
        'Uptime', 'Questions', 'Connections', 'Aborted_connects', 'Threads_connected', 'Threads_running', 'Slow_queries',
        'Created_tmp_tables', 'Created_tmp_disk_tables', 'Innodb_buffer_pool_reads', 'Innodb_buffer_pool_read_requests',
        'Innodb_row_lock_waits', 'Innodb_row_lock_time', 'Innodb_row_lock_current_waits', 'Innodb_log_waits',
    ];

    private DbalCatalogReader $catalog;

    public function __construct(Connection $connection)
    {
        $this->catalog = DbalCatalogReader::mysql($connection);
    }

    public function status(): DiagnosticStatus
    {
        try {
            $this->catalog->read(static fn (Connection $c): mixed => $c->fetchAllAssociative("SHOW GLOBAL STATUS LIKE 'Uptime'"));
        } catch (DiagnosticsFailedException $e) {
            $previous = $e->getPrevious();
            if ($previous instanceof DriverException && self::isRefusal($previous)) {
                return DiagnosticStatus::denied('SELECT ON performance_schema');
            }

            throw $e;
        }

        return DiagnosticStatus::available();
    }

    public function sample(): HealthSample
    {
        $counters = $this->catalog->read(static function (Connection $c): array {
            $names = implode(', ', array_map(static fn (string $n): string => "'".$n."'", self::COUNTERS));
            $counters = [];
            foreach ($c->fetchAllAssociative('SHOW GLOBAL STATUS WHERE Variable_name IN ('.$names.')') as $row) {
                $row = new RowReader($row);
                $counters[$row->string('Variable_name')] = $row->string('Value');
            }
            foreach ($c->fetchAllAssociative("SHOW GLOBAL VARIABLES LIKE 'max_connections'") as $row) {
                $row = new RowReader($row);
                $counters['max_connections'] = $row->string('Value');
            }

            return $counters;
        });
        $uptime = $counters['Uptime'] ?? null;

        return new HealthSample(microtime(true), $counters, null !== $uptime && 1 === preg_match('/^\d+$/D', $uptime) ? (int) $uptime : null);
    }

    public function indicators(HealthWindow $window): array
    {
        return MySqlHealthIndicators::compute($window);
    }

    private static function isRefusal(DriverException $e): bool
    {
        return \in_array($e->getCode(), [self::ER_TABLEACCESS_DENIED, self::ER_SPECIFIC_ACCESS_DENIED, self::ER_DBACCESS_DENIED], true);
    }
}
