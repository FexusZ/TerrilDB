<?php

declare(strict_types=1);

namespace App\Driver\PostgreSql;

use App\Core\Diagnostics\DiagnosticStatus;
use App\Core\Diagnostics\Health\HealthSample;
use App\Core\Diagnostics\Health\HealthWindow;
use App\Core\Diagnostics\Health\PostgreSqlHealthIndicators;
use App\Core\Diagnostics\HealthDiagnostics;
use App\Driver\DbalCatalogReader;
use App\Driver\RowReader;
use Doctrine\DBAL\Connection;

/**
 * Un relevé de `pg_stat_database` pour la base de la connexion (lisible par tout compte), le nombre de sessions clientes et
 * le plafond `max_connections`. Le repère de remise à zéro est `stats_reset`, le temps de marche `pg_postmaster_start_time()`.
 */
final readonly class PostgreSqlHealthDiagnostics implements HealthDiagnostics
{
    private const COLUMNS = ['xact_commit', 'xact_rollback', 'blks_read', 'blks_hit', 'tup_returned', 'tup_fetched', 'temp_files', 'temp_bytes', 'deadlocks'];

    private DbalCatalogReader $catalog;

    public function __construct(Connection $connection)
    {
        $this->catalog = DbalCatalogReader::postgresql($connection);
    }

    public function status(): DiagnosticStatus
    {
        return DiagnosticStatus::available();
    }

    public function sample(): HealthSample
    {
        return $this->catalog->read(function (Connection $c): HealthSample {
            $row = $c->fetchAssociative(
                'SELECT '.implode(', ', self::COLUMNS).",
                        stats_reset::text AS epoch,
                        (SELECT count(*) FROM pg_stat_activity WHERE backend_type = 'client backend') AS connections,
                        current_setting('max_connections') AS max_connections,
                        EXTRACT(EPOCH FROM (now() - pg_postmaster_start_time()))::bigint AS uptime
                   FROM pg_stat_database WHERE datname = current_database()",
            );
            if (false === $row) {
                return new HealthSample(microtime(true), []);
            }
            $reader = new RowReader($row);
            $counters = [];
            foreach ([...self::COLUMNS, 'connections', 'max_connections'] as $name) {
                $counters[$name] = $reader->string($name);
            }

            return new HealthSample(microtime(true), $counters, $reader->int('uptime'), $reader->nullableString('epoch'));
        });
    }

    public function indicators(HealthWindow $window): array
    {
        return PostgreSqlHealthIndicators::compute($window);
    }
}
