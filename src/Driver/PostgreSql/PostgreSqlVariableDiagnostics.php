<?php

declare(strict_types=1);

namespace App\Driver\PostgreSql;

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
 * Paramètres de PostgreSQL par pg_settings, en lecture seule (tout compte le lit ; sans pg_read_all_settings, pg_monitor ou
 * superutilisateur le serveur laisse vides les valeurs réservées, qui restent alors sans valeur ici).
 * « Modifiée » = l'origine n'est ni `default` ni `override` (valeur posée par le serveur lui-même, comme data_directory) :
 * `boot_val` n'est qu'un repère, certains défauts (client_encoding…) sont décidés à l'initialisation.
 * Redémarrage : contexte `postmaster` = nécessaire, `pending_restart` = valeur posée mais pas encore prise.
 */
final readonly class PostgreSqlVariableDiagnostics implements VariableDiagnostics
{
    private DbalCatalogReader $catalog;

    public function __construct(Connection $connection)
    {
        $this->catalog = DbalCatalogReader::postgresql($connection);
    }

    public function status(): DiagnosticStatus
    {
        return DiagnosticStatus::available();
    }

    public function variables(VariableQuery $query): VariableReport
    {
        return $this->catalog->read(static function (Connection $c) use ($query): VariableReport {
            $where = 'true';
            $params = [];
            if (null !== $query->search) {
                $where .= ' AND strpos(lower(name), lower(?)) > 0';
                $params[] = $query->search;
            }
            if ($query->changedOnly) {
                $where .= " AND source NOT IN ('default', 'override')";
            }

            $rows = $c->fetchAllAssociative(
                "SELECT name, setting AS value, boot_val AS default_value, unit, source, context, pending_restart,
                        (source NOT IN ('default', 'override')) AS changed
                   FROM pg_settings
                  WHERE {$where}
                  ORDER BY name COLLATE \"C\"
                  LIMIT ".($query->limit + 1),
                $params,
            );

            $variables = [];
            foreach (\array_slice($rows, 0, $query->limit) as $row) {
                $row = new RowReader($row);
                $variables[] = new ServerVariable(
                    $row->string('name'),
                    $row->nullableString('value'),
                    $row->nullableString('default_value'),
                    $row->bool('changed'),
                    $row->nullableString('source'),
                    $row->bool('pending_restart') ? RestartNeed::Pending : ('postmaster' === $row->string('context') ? RestartNeed::Required : null),
                    null,
                    $row->nullableString('unit'),
                );
            }

            return new VariableReport(DiagnosticStatus::available(), $variables, \count($rows) > $query->limit);
        });
    }
}
