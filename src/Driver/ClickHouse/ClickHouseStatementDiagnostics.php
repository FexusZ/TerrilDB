<?php

declare(strict_types=1);

namespace App\Driver\ClickHouse;

use App\Core\Diagnostics\DiagnosticStatus;
use App\Core\Diagnostics\DigestOrder;
use App\Core\Diagnostics\DigestQuery;
use App\Core\Diagnostics\DigestReport;
use App\Core\Diagnostics\Exception\DiagnosticsFailedException;
use App\Core\Diagnostics\StatementDiagnostics;
use App\Core\Diagnostics\StatementDigest;
use App\Driver\RowReader;

/**
 * Requêtes coûteuses de ClickHouse par empreinte : system.query_log (requêtes terminées), regroupées par
 * `normalized_query_hash`, le texte passant par `normalizeQuery` du serveur (les valeurs y deviennent des « ? »). Les requêtes
 * qui ne touchent que les tables système (dont celles de cette supervision) sont écartées. Lecture seule imposée par le moteur
 * (`readonly=1`) ; un journal absent (désactivé dans la configuration du serveur) est l'état « source éteinte », un refus
 * d'accès l'état « droits insuffisants ». Le journal est écrit par lots : les toutes dernières requêtes peuvent manquer.
 */
final readonly class ClickHouseStatementDiagnostics implements StatementDiagnostics
{
    private const UNKNOWN_TABLE = 60;
    private const ACCESS_DENIED = 497;
    private const PRIVILEGE = 'SELECT ON system.query_log';
    private const SETTINGS = ['readonly' => 1, 'max_execution_time' => 10];

    public function __construct(private ClickHouseHttp $http)
    {
    }

    public function status(): DiagnosticStatus
    {
        try {
            $this->http->query('SELECT 1 FROM system.query_log LIMIT 0', self::SETTINGS);
        } catch (ClickHouseException $e) {
            return $this->state($e) ?? throw DiagnosticsFailedException::create($e);
        }

        return DiagnosticStatus::available();
    }

    public function top(DigestQuery $query): DigestReport
    {
        // Le classement vient d'une liste fermée : jamais un nom de colonne reçu tel quel.
        $order = match ($query->order) {
            DigestOrder::TotalTime => 'total_ms',
            DigestOrder::MeanTime => 'mean_ms',
            DigestOrder::Calls => 'calls',
        };

        try {
            $response = $this->http->query(
                "SELECT substring(normalizeQuery(any(query)), 1, 256) AS text, any(current_database) AS db, count() AS calls,
                        sum(query_duration_ms) AS total_ms, avg(query_duration_ms) AS mean_ms, max(query_duration_ms) AS max_ms,
                        sum(read_rows) AS examined, sum(result_rows) AS returned,
                        formatDateTime(min(event_time), '%Y-%m-%dT%H:%i:%SZ', 'UTC') AS first_seen,
                        formatDateTime(max(event_time), '%Y-%m-%dT%H:%i:%SZ', 'UTC') AS last_seen
                   FROM system.query_log
                  WHERE type = 'QueryFinish' AND is_initial_query
                    AND NOT (notEmpty(databases) AND arrayAll(d -> d IN ('system', 'information_schema', 'INFORMATION_SCHEMA'), databases))
                  GROUP BY normalized_query_hash
                  ORDER BY {$order} DESC, normalized_query_hash
                  LIMIT {$query->limit}",
                self::SETTINGS,
            );
        } catch (ClickHouseException $e) {
            $state = $this->state($e);
            if (null === $state) {
                throw DiagnosticsFailedException::create($e);
            }

            return new DigestReport($state, $query->order, []);
        }

        $digests = [];
        foreach ($response->records() as $record) {
            $row = new RowReader($record);
            $digests[] = new StatementDigest(
                $row->nullableString('text'),
                $row->nullableString('db'),
                $row->int('calls'),
                $row->float('total_ms'),
                $row->float('mean_ms'),
                $row->nullableFloat('max_ms'),
                $row->int('examined'),
                $row->int('returned'),
                null,
                null,
                null,
                $row->nullableString('first_seen'),
                $row->nullableString('last_seen'),
            );
        }

        return new DigestReport(DiagnosticStatus::available(), $query->order, $digests);
    }

    private function state(ClickHouseException $e): ?DiagnosticStatus
    {
        return match ($e->engineCode) {
            self::UNKNOWN_TABLE => DiagnosticStatus::disabled('query_log_off'),
            self::ACCESS_DENIED => DiagnosticStatus::denied(self::PRIVILEGE),
            default => null,
        };
    }
}
