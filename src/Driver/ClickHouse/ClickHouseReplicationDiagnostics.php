<?php

declare(strict_types=1);

namespace App\Driver\ClickHouse;

use App\Core\Diagnostics\DiagnosticStatus;
use App\Core\Diagnostics\Exception\DiagnosticsFailedException;
use App\Core\Diagnostics\Replication\ReplicatedTable;
use App\Core\Diagnostics\Replication\ReplicatedTables;
use App\Core\Diagnostics\Replication\ReplicationExtras;
use App\Core\Diagnostics\Replication\ReplicationReport;
use App\Core\Diagnostics\Replication\ReplicationRole;
use App\Core\Diagnostics\Replication\ReplicationSettings;
use App\Core\Diagnostics\ReplicationDiagnostics;
use App\Driver\RowReader;

/**
 * Réplication de ClickHouse : les tables répliquées (`system.replicas`), jugées une à une (lecture seule, `readonly=1`, 200 au
 * plus, les plus en retard d'abord). Ni chemin ZooKeeper / Keeper, ni nom de réplica, ni message d'exception du coordinateur :
 * ils peuvent citer un hôte. Sans table répliquée le serveur n'a « pas de réplication » ; des tables répliquées donnent le rôle
 * `peer` (ClickHouse n'a pas de source ni de réplica fixes). Un refus d'accès (code 497) est un état.
 */
final readonly class ClickHouseReplicationDiagnostics implements ReplicationDiagnostics
{
    private const LIMIT = 200;
    private const ACCESS_DENIED = 497;
    private const PRIVILEGE = 'SELECT ON system.replicas';
    private const SETTINGS = ['readonly' => 1, 'max_execution_time' => 10];

    public function __construct(private ClickHouseHttp $http)
    {
    }

    public function status(): DiagnosticStatus
    {
        try {
            $this->http->query('SELECT 1 FROM system.replicas LIMIT 0', self::SETTINGS);
        } catch (ClickHouseException $e) {
            return self::ACCESS_DENIED === $e->engineCode ? DiagnosticStatus::denied(self::PRIVILEGE) : throw DiagnosticsFailedException::create($e);
        }

        return DiagnosticStatus::available();
    }

    public function replication(): ReplicationReport
    {
        try {
            $response = $this->http->query(
                'SELECT database, `table`, is_readonly, is_session_expired, absolute_delay, queue_size, inserts_in_queue, lost_part_count
                   FROM system.replicas
                  ORDER BY absolute_delay DESC, database, `table`
                  LIMIT '.(self::LIMIT + 1),
                self::SETTINGS,
            );
        } catch (ClickHouseException $e) {
            if (self::ACCESS_DENIED === $e->engineCode) {
                return ReplicationReport::unreadable(DiagnosticStatus::denied(self::PRIVILEGE));
            }

            throw DiagnosticsFailedException::create($e);
        }

        $records = $response->records();
        $tables = [];
        foreach (\array_slice($records, 0, self::LIMIT) as $record) {
            $row = new RowReader($record);
            $tables[] = new ReplicatedTable(
                $row->string('database'),
                $row->string('table'),
                $row->bool('is_readonly'),
                $row->bool('is_session_expired'),
                $row->int('absolute_delay'),
                $row->int('queue_size'),
                $row->int('inserts_in_queue'),
                $row->int('lost_part_count'),
            );
        }

        return new ReplicationReport(
            DiagnosticStatus::available(),
            [] === $tables ? ReplicationRole::None : ReplicationRole::Peer,
            [],
            new ReplicationSettings(null, null, null, null),
            null,
            new ReplicationExtras(tables: new ReplicatedTables(DiagnosticStatus::available(), $tables, \count($records) > self::LIMIT)),
        );
    }
}
