<?php

declare(strict_types=1);

namespace App\Driver\MySql;

use App\Core\Connection\Engine;
use App\Core\Diagnostics\DiagnosticStatus;
use App\Core\Diagnostics\Log\ErrorLogEntry;
use App\Core\Diagnostics\Log\ErrorLogReport;
use App\Core\Diagnostics\Log\SlowQuery;
use App\Core\Diagnostics\Log\SlowQueryReport;
use App\Core\Diagnostics\LogDiagnostics;
use App\Core\Guard\SqlDialect;
use App\Driver\DbalCatalogReader;
use App\Driver\RowReader;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\DriverException;

/**
 * Journaux de MySQL et MariaDB lus en SQL, jamais par un fichier de l'hôte. Journal d'erreurs : `performance_schema.error_log`
 * (MySQL 8.0.22 et plus ; MariaDB n'a que le fichier, état « non supporté »). Journal des requêtes lentes : `mysql.slow_log`,
 * seulement si `log_output` contient TABLE et `slow_query_log` est allumé ; sinon l'état dit lequel manque (rien n'est
 * activé d'ici : la commande d'activation se lit dans l'interface). Les 200 lignes les plus récentes, textes masqués.
 */
final readonly class MySqlLogDiagnostics implements LogDiagnostics
{
    private const LIMIT = 200;
    private const ER_DBACCESS_DENIED = 1044;
    private const ER_TABLEACCESS_DENIED = 1142;
    private const ER_SPECIFIC_ACCESS_DENIED = 1227;
    private const ER_NO_SUCH_TABLE = 1146;

    private DbalCatalogReader $catalog;

    public function __construct(Connection $connection, private Engine $engine)
    {
        $this->catalog = DbalCatalogReader::mysql($connection);
    }

    public function status(): DiagnosticStatus
    {
        $error = $this->errorLog()->status;
        if ($error->isAvailable()) {
            return $error;
        }
        $slow = $this->slowQueries()->status;

        return $slow->isAvailable() ? $slow : $error;
    }

    public function errorLog(): ErrorLogReport
    {
        if (Engine::MariaDb === $this->engine) {
            return new ErrorLogReport(DiagnosticStatus::unsupported('log_is_a_file'), [], false);
        }

        return $this->catalog->read(function (Connection $c): ErrorLogReport {
            if (0 === (new RowReader(['on' => $c->fetchOne('SELECT @@performance_schema')]))->int('on')) {
                return new ErrorLogReport(DiagnosticStatus::disabled('performance_schema_off'), [], false);
            }

            try {
                $rows = $c->fetchAllAssociative(
                    'SELECT UNIX_TIMESTAMP(LOGGED) AS logged, PRIO AS prio, SUBSYSTEM AS subsystem, ERROR_CODE AS code, LEFT(DATA, 2000) AS data
                       FROM performance_schema.error_log
                      ORDER BY LOGGED DESC
                      LIMIT '.(self::LIMIT + 1),
                );
            } catch (DriverException $e) {
                return new ErrorLogReport(self::refusal($e, 'SELECT ON performance_schema', 'error_log_missing'), [], false);
            }

            $entries = [];
            foreach (\array_slice($rows, 0, self::LIMIT) as $row) {
                $row = new RowReader($row);
                $entries[] = new ErrorLogEntry(self::seconds($row->nullableString('logged')), $row->string('prio'), $row->nullableString('subsystem'), $row->nullableString('code'), $row->nullableString('data'));
            }

            return new ErrorLogReport(DiagnosticStatus::available(), $entries, \count($rows) > self::LIMIT);
        });
    }

    public function slowQueries(): SlowQueryReport
    {
        return $this->catalog->read(function (Connection $c): SlowQueryReport {
            $settings = new RowReader($c->fetchAssociative('SELECT @@GLOBAL.log_output AS output, @@GLOBAL.slow_query_log AS enabled, @@GLOBAL.long_query_time AS threshold') ?: []);
            $threshold = self::seconds($settings->nullableString('threshold'));
            if (!str_contains(strtoupper($settings->string('output')), 'TABLE')) {
                return new SlowQueryReport(DiagnosticStatus::disabled('log_output_without_table'), [], false, $threshold);
            }
            if (0 === $settings->int('enabled')) {
                return new SlowQueryReport(DiagnosticStatus::disabled('slow_query_log_off'), [], false, $threshold);
            }

            try {
                $rows = $c->fetchAllAssociative(
                    "SELECT UNIX_TIMESTAMP(start_time) AS started, SUBSTRING_INDEX(user_host, '[', 1) AS usr, db,
                            TIME_TO_SEC(query_time) + MICROSECOND(query_time) / 1000000 AS duration,
                            TIME_TO_SEC(lock_time) + MICROSECOND(lock_time) / 1000000 AS locked,
                            rows_sent, rows_examined, CONVERT(LEFT(sql_text, 4096) USING utf8mb4) AS sql_text
                       FROM mysql.slow_log
                      ORDER BY start_time DESC
                      LIMIT ".(self::LIMIT + 1),
                );
            } catch (DriverException $e) {
                return new SlowQueryReport(self::refusal($e, 'SELECT ON mysql.slow_log', 'slow_log_missing'), [], false, $threshold);
            }

            $queries = [];
            foreach (\array_slice($rows, 0, self::LIMIT) as $row) {
                $row = new RowReader($row);
                $user = trim((string) $row->nullableString('usr'));
                $duration = self::seconds($row->nullableString('duration'));
                $locked = self::seconds($row->nullableString('locked'));
                $queries[] = new SlowQuery(
                    self::seconds($row->nullableString('started')),
                    '' === $user ? null : $user,
                    $row->nullableString('db'),
                    null === $duration ? null : $duration * 1000,
                    null === $locked ? null : $locked * 1000,
                    $row->nullableInt('rows_sent'),
                    $row->nullableInt('rows_examined'),
                    $row->nullableString('sql_text'),
                    SqlDialect::MySql,
                );
            }

            return new SlowQueryReport(DiagnosticStatus::available(), $queries, \count($rows) > self::LIMIT, $threshold);
        });
    }

    private static function seconds(?string $value): ?float
    {
        return null !== $value && is_numeric($value) ? (float) $value : null;
    }

    /**
     * Un refus du serveur (ou une table qui n'existe pas dans cette série) est un état, jamais une erreur ; tout autre échec remonte.
     */
    private static function refusal(DriverException $e, string $privilege, string $missing): DiagnosticStatus
    {
        return match ($e->getCode()) {
            self::ER_TABLEACCESS_DENIED, self::ER_SPECIFIC_ACCESS_DENIED, self::ER_DBACCESS_DENIED => DiagnosticStatus::denied($privilege),
            self::ER_NO_SUCH_TABLE => DiagnosticStatus::unsupported($missing),
            default => throw $e,
        };
    }
}
