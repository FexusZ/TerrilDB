<?php

declare(strict_types=1);

namespace App\Driver\MySql;

use App\Core\Connection\Engine;
use App\Core\Diagnostics\DiagnosticStatus;
use App\Core\Diagnostics\RestartNeed;
use App\Core\Diagnostics\ServerVariable;
use App\Core\Diagnostics\VariableDiagnostics;
use App\Core\Diagnostics\VariableQuery;
use App\Core\Diagnostics\VariableReport;
use App\Driver\DbalCatalogReader;
use App\Driver\RowReader;
use Doctrine\DBAL\Connection;

/**
 * Variables globales de MySQL et de MariaDB, en lecture seule.
 *
 * MySQL : performance_schema.global_variables + variables_info. Le moteur ne donne pas de valeur par défaut : l'origine
 * (`COMPILED` = jamais touchée ; `GLOBAL`/`EXPLICIT` = fichier, `COMMAND_LINE`, `PERSISTED`, `DYNAMIC` = changée à chaud)
 * dit si elle diffère. Il ne dit pas non plus ce qui demande un redémarrage. performance_schema éteint = source éteinte.
 * MariaDB : information_schema.SYSTEM_VARIABLES, lisible par tout compte : valeur par défaut, origine (`COMPILE-TIME` = jamais
 * touchée) et lecture seule (= redémarrage nécessaire).
 * Le nom cherché est un paramètre lié, comparé par INSTR (« _ » et « % » restent littéraux).
 */
final readonly class MySqlVariableDiagnostics implements VariableDiagnostics
{
    private DbalCatalogReader $catalog;

    public function __construct(Connection $connection, private Engine $engine)
    {
        $this->catalog = DbalCatalogReader::mysql($connection);
    }

    public function status(): DiagnosticStatus
    {
        return $this->catalog->read(fn (Connection $c): DiagnosticStatus => $this->check($c));
    }

    public function variables(VariableQuery $query): VariableReport
    {
        return $this->catalog->read(function (Connection $c) use ($query): VariableReport {
            $status = $this->check($c);
            if (!$status->isAvailable()) {
                return new VariableReport($status, [], false);
            }

            $rows = Engine::MariaDb === $this->engine ? $this->mariaDb($c, $query) : $this->mySql($c, $query);
            $variables = [];
            foreach (\array_slice($rows, 0, $query->limit) as $row) {
                $row = new RowReader($row);
                $variables[] = new ServerVariable(
                    $row->string('name'),
                    $row->nullableString('value'),
                    $row->nullableString('default_value'),
                    $row->bool('changed'),
                    $row->nullableString('source'),
                    $row->bool('read_only') ? RestartNeed::Required : null,
                    $row->nullableString('scope'),
                );
            }

            return new VariableReport($status, $variables, \count($rows) > $query->limit);
        });
    }

    private function check(Connection $connection): DiagnosticStatus
    {
        if (Engine::MariaDb === $this->engine) {
            return DiagnosticStatus::available();
        }

        return 1 === (new RowReader(['v' => $connection->fetchOne('SELECT @@performance_schema')]))->int('v') ? DiagnosticStatus::available() : DiagnosticStatus::disabled('performance_schema_off');
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function mySql(Connection $c, VariableQuery $query): array
    {
        $where = '1 = 1';
        $params = [];
        if (null !== $query->search) {
            $where .= ' AND INSTR(LOWER(g.VARIABLE_NAME), LOWER(?)) > 0';
            $params[] = $query->search;
        }
        if ($query->changedOnly) {
            $where .= " AND i.VARIABLE_SOURCE <> 'COMPILED'";
        }

        return $c->fetchAllAssociative(
            "SELECT g.VARIABLE_NAME AS name, g.VARIABLE_VALUE AS value, NULL AS default_value,
                    (COALESCE(i.VARIABLE_SOURCE, 'COMPILED') <> 'COMPILED') AS changed, i.VARIABLE_SOURCE AS source,
                    0 AS read_only, 'global' AS scope
               FROM performance_schema.global_variables g
               LEFT JOIN performance_schema.variables_info i ON i.VARIABLE_NAME = g.VARIABLE_NAME
              WHERE {$where}
              ORDER BY BINARY g.VARIABLE_NAME
              LIMIT ".($query->limit + 1),
            $params,
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function mariaDb(Connection $c, VariableQuery $query): array
    {
        $where = '1 = 1';
        $params = [];
        if (null !== $query->search) {
            $where .= ' AND INSTR(LOWER(VARIABLE_NAME), LOWER(?)) > 0';
            $params[] = $query->search;
        }
        if ($query->changedOnly) {
            $where .= " AND GLOBAL_VALUE_ORIGIN <> 'COMPILE-TIME'";
        }

        return $c->fetchAllAssociative(
            "SELECT LOWER(VARIABLE_NAME) AS name, GLOBAL_VALUE AS value, DEFAULT_VALUE AS default_value,
                    (GLOBAL_VALUE_ORIGIN <> 'COMPILE-TIME') AS changed, GLOBAL_VALUE_ORIGIN AS source,
                    (READ_ONLY = 'YES') AS read_only, IF(VARIABLE_SCOPE = 'GLOBAL', 'global', 'session') AS scope
               FROM information_schema.SYSTEM_VARIABLES
              WHERE {$where}
              ORDER BY BINARY LOWER(VARIABLE_NAME)
              LIMIT ".($query->limit + 1),
            $params,
        );
    }
}
