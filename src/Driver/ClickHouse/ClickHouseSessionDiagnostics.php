<?php

declare(strict_types=1);

namespace App\Driver\ClickHouse;

use App\Core\Diagnostics\DiagnosticStatus;
use App\Core\Diagnostics\Exception\DiagnosticsFailedException;
use App\Core\Diagnostics\ServerSession;
use App\Core\Diagnostics\SessionDiagnostics;
use App\Core\Diagnostics\SessionReport;
use App\Core\Guard\SqlDialect;
use App\Driver\RowReader;

/**
 * Requêtes en cours de ClickHouse par system.processes (pas de session inactive : HTTP ne garde rien entre deux requêtes).
 * Lecture seule imposée par le moteur (`readonly=1`) ; un refus d'accès à la table devient l'état « droits insuffisants ».
 */
final readonly class ClickHouseSessionDiagnostics implements SessionDiagnostics
{
    private const LIMIT = 200;
    private const ACCESS_DENIED = 497;
    private const PRIVILEGE = 'SELECT ON system.processes';
    private const SETTINGS = ['readonly' => 1, 'max_execution_time' => 10];

    public function __construct(private ClickHouseHttp $http)
    {
    }

    public function status(): DiagnosticStatus
    {
        try {
            $this->http->query('SELECT 1 FROM system.processes LIMIT 0', self::SETTINGS);
        } catch (ClickHouseException $e) {
            return $this->denied($e) ? DiagnosticStatus::denied(self::PRIVILEGE) : throw DiagnosticsFailedException::create($e);
        }

        return DiagnosticStatus::available();
    }

    public function sessions(): SessionReport
    {
        // L'identifiant de requête choisi ici reconnaît « notre » ligne dans la liste.
        $queryId = 'terrildb-'.bin2hex(random_bytes(8));
        try {
            $response = $this->http->query(
                'SELECT query_id, user, address, current_database AS db, elapsed, substring(query, 1, 4096) AS text
                   FROM system.processes
                  ORDER BY elapsed DESC
                  LIMIT '.(self::LIMIT + 1),
                self::SETTINGS + ['query_id' => $queryId],
            );
        } catch (ClickHouseException $e) {
            if ($this->denied($e)) {
                return new SessionReport(DiagnosticStatus::denied(self::PRIVILEGE), [], false);
            }

            throw DiagnosticsFailedException::create($e);
        }

        $records = $response->records();
        $sessions = [];
        foreach (\array_slice($records, 0, self::LIMIT) as $record) {
            $row = new RowReader($record);
            $address = $row->nullableString('address');
            $sessions[] = new ServerSession(
                $row->string('query_id'),
                $row->nullableString('user'),
                null === $address ? null : (string) preg_replace('/^::ffff:/D', '', $address),
                $row->nullableString('db'),
                'running',
                (int) floor((float) $row->string('elapsed')),
                $row->nullableString('text'),
                $row->string('query_id') === $queryId,
                SqlDialect::ClickHouse,
            );
        }

        return new SessionReport(DiagnosticStatus::available(), $sessions, \count($records) > self::LIMIT);
    }

    private function denied(ClickHouseException $e): bool
    {
        return self::ACCESS_DENIED === $e->engineCode;
    }
}
