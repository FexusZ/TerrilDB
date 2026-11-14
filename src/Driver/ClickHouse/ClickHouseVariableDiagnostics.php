<?php

declare(strict_types=1);

namespace App\Driver\ClickHouse;

use App\Core\Diagnostics\DiagnosticStatus;
use App\Core\Diagnostics\Exception\DiagnosticsFailedException;
use App\Core\Diagnostics\RestartNeed;
use App\Core\Diagnostics\ServerVariable;
use App\Core\Diagnostics\VariableDiagnostics;
use App\Core\Diagnostics\VariableQuery;
use App\Core\Diagnostics\VariableReport;
use App\Driver\RowReader;

/**
 * Réglages de ClickHouse : system.settings (réglages de l'utilisateur, portée `session`) et system.server_settings (réglages du
 * serveur, portée `server`, avec « redémarrage nécessaire » quand ils ne se changent pas à chaud). `changed` vient du moteur.
 * Lecture seule imposée par le moteur (`readonly=1`, qui compte donc lui-même parmi les réglages modifiés).
 */
final readonly class ClickHouseVariableDiagnostics implements VariableDiagnostics
{
    private const ACCESS_DENIED = 497;
    private const SETTINGS = ['readonly' => 1, 'max_execution_time' => 10];
    private const PRIVILEGE = 'SELECT ON system.server_settings';

    public function __construct(private ClickHouseHttp $http)
    {
    }

    public function status(): DiagnosticStatus
    {
        try {
            $this->http->query('SELECT 1 FROM system.settings LIMIT 0 UNION ALL SELECT 1 FROM system.server_settings LIMIT 0', self::SETTINGS);
        } catch (ClickHouseException $e) {
            return self::ACCESS_DENIED === $e->engineCode ? DiagnosticStatus::denied(self::PRIVILEGE) : throw DiagnosticsFailedException::create($e);
        }

        return DiagnosticStatus::available();
    }

    public function variables(VariableQuery $query): VariableReport
    {
        $filter = '1';
        $params = ['limit' => (string) ($query->limit + 1)];
        if (null !== $query->search) {
            $filter .= ' AND positionCaseInsensitive(name, {q:String}) > 0';
            $params['q'] = $query->search;
        }
        if ($query->changedOnly) {
            $filter .= ' AND changed = 1';
        }

        try {
            $records = $this->http->query(
                "SELECT name, value, `default` AS default_value, changed, scope, restart FROM (
                    SELECT name, value, `default`, changed, 'session' AS scope, 0 AS restart FROM system.settings
                    UNION ALL
                    SELECT name, value, `default`, changed, 'server' AS scope, (changeable_without_restart = 'No') AS restart FROM system.server_settings
                 ) WHERE {$filter} ORDER BY name, scope LIMIT {limit:UInt32}",
                self::SETTINGS,
                $params,
            )->records();
        } catch (ClickHouseException $e) {
            return self::ACCESS_DENIED === $e->engineCode ? new VariableReport(DiagnosticStatus::denied(self::PRIVILEGE), [], false) : throw DiagnosticsFailedException::create($e);
        }

        $variables = [];
        foreach (\array_slice($records, 0, $query->limit) as $record) {
            $row = new RowReader($record);
            $variables[] = new ServerVariable(
                $row->string('name'),
                $row->nullableString('value'),
                $row->nullableString('default_value'),
                $row->bool('changed'),
                null,
                $row->bool('restart') ? RestartNeed::Required : null,
                $row->string('scope'),
            );
        }

        return new VariableReport(DiagnosticStatus::available(), $variables, \count($records) > $query->limit);
    }
}
