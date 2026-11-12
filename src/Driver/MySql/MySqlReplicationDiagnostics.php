<?php

declare(strict_types=1);

namespace App\Driver\MySql;

use App\Core\Connection\Engine;
use App\Core\Diagnostics\DiagnosticStatus;
use App\Core\Diagnostics\Replication\ReplicationError;
use App\Core\Diagnostics\Replication\ReplicationReport;
use App\Core\Diagnostics\ReplicationDiagnostics;
use App\Driver\DbalCatalogReader;
use App\Driver\RowReader;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\DriverException;

/**
 * Réplication de MySQL et de MariaDB : `SHOW REPLICA STATUS` (une ligne par canal), `SHOW REPLICAS` / `SHOW REPLICA HOSTS` côté source,
 * quelques variables globales, et pour MySQL les GTID reçus mais pas appliqués (`GTID_SUBTRACT`) et l'erreur du fil applicateur
 * (`performance_schema`, lecture facultative). Seule la syntaxe `REPLICA` est employée (les mots SLAVE et MASTER sont retirés de
 * MySQL 8.4) : un serveur plus ancien est dit « non pris en charge », jamais interrogé autrement. Rien n'est exécuté qui change
 * la réplication. Sans le privilège de lecture (REPLICATION CLIENT, SLAVE MONITOR sur MariaDB) l'état le dit ; sans REPLICATION SLAVE
 * (REPLICATION MASTER ADMIN sur MariaDB) le nombre de réplicas reste inconnu (null), jamais présenté comme zéro.
 */
final readonly class MySqlReplicationDiagnostics implements ReplicationDiagnostics
{
    private const ER_SPECIFIC_ACCESS_DENIED = 1227;
    private const ER_TABLEACCESS_DENIED = 1142;
    private const VARIABLES = ['read_only', 'super_read_only', 'binlog_format', 'gtid_mode', 'gtid_slave_pos'];

    private DbalCatalogReader $catalog;

    public function __construct(private Connection $connection, private Engine $engine)
    {
        $this->catalog = DbalCatalogReader::mysql($connection);
    }

    public function status(): DiagnosticStatus
    {
        return $this->catalog->read(function (Connection $c): DiagnosticStatus {
            $unsupported = $this->versionStatus($c);
            if (null !== $unsupported) {
                return $unsupported;
            }

            try {
                $c->fetchAllAssociative('SHOW REPLICA STATUS');
            } catch (DriverException $e) {
                if (self::ER_SPECIFIC_ACCESS_DENIED === $e->getCode()) {
                    return DiagnosticStatus::denied($this->privilege());
                }

                throw $e;
            }

            return DiagnosticStatus::available();
        });
    }

    public function replication(): ReplicationReport
    {
        return $this->catalog->read(function (Connection $c): ReplicationReport {
            $unsupported = $this->versionStatus($c);
            if (null !== $unsupported) {
                return ReplicationReport::unreadable($unsupported);
            }

            try {
                $rows = $c->fetchAllAssociative('SHOW REPLICA STATUS');
            } catch (DriverException $e) {
                if (self::ER_SPECIFIC_ACCESS_DENIED === $e->getCode()) {
                    return ReplicationReport::unreadable(DiagnosticStatus::denied($this->privilege()));
                }

                throw $e;
            }

            $unapplied = [];
            $workerErrors = [];
            if (Engine::MySql === $this->engine) {
                foreach ($rows as $row) {
                    $channel = (new RowReader($row))->nullableString('Channel_Name') ?? '';
                    $retrieved = (new RowReader($row))->nullableString('Retrieved_Gtid_Set');
                    $unapplied[$channel] = null === $retrieved ? '' : (new RowReader(['d' => $c->fetchOne('SELECT GTID_SUBTRACT(?, @@GLOBAL.gtid_executed)', [$retrieved])]))->nullableString('d') ?? '';
                }
                $workerErrors = $this->workerErrors($c);
            }

            return MySqlReplicationReport::build($this->engine, $rows, $this->variables($c), $this->replicaCount($c), $unapplied, $workerErrors);
        });
    }

    /**
     * @return array<string, string>
     */
    private function variables(Connection $c): array
    {
        $names = implode(', ', array_map(static fn (string $n): string => "'".$n."'", self::VARIABLES));
        $variables = [];
        foreach ($c->fetchAllAssociative('SHOW GLOBAL VARIABLES WHERE Variable_name IN ('.$names.')') as $row) {
            $row = new RowReader($row);
            $variables[$row->string('Variable_name')] = $row->string('Value');
        }

        return $variables;
    }

    private function replicaCount(Connection $c): ?int
    {
        try {
            return \count($c->fetchAllAssociative(Engine::MariaDb === $this->engine ? 'SHOW REPLICA HOSTS' : 'SHOW REPLICAS'));
        } catch (DriverException $e) {
            if (self::ER_SPECIFIC_ACCESS_DENIED === $e->getCode()) {
                return null;
            }

            throw $e;
        }
    }

    /**
     * L'erreur du fil applicateur, bien plus explicite que celle du coordinateur (table, valeur en cause). Facultative : sans
     * performance_schema ou sans droit, le texte du coordinateur reste.
     *
     * @return array<string, ReplicationError>
     */
    private function workerErrors(Connection $c): array
    {
        try {
            $rows = $c->fetchAllAssociative('SELECT CHANNEL_NAME, LAST_ERROR_NUMBER, LAST_ERROR_MESSAGE FROM performance_schema.replication_applier_status_by_worker WHERE LAST_ERROR_NUMBER <> 0 ORDER BY LAST_ERROR_TIMESTAMP DESC');
        } catch (DriverException $e) {
            if (\in_array($e->getCode(), [self::ER_TABLEACCESS_DENIED, self::ER_SPECIFIC_ACCESS_DENIED], true)) {
                return [];
            }

            throw $e;
        }

        $errors = [];
        foreach ($rows as $row) {
            $row = new RowReader($row);
            $channel = $row->nullableString('CHANNEL_NAME') ?? '';
            // Le plus récent d'abord : on garde la première erreur lue de chaque canal.
            $errors[$channel] ??= ReplicationError::from($row->nullableInt('LAST_ERROR_NUMBER'), $row->nullableString('LAST_ERROR_MESSAGE'));
        }

        return array_filter($errors);
    }

    private function versionStatus(Connection $c): ?DiagnosticStatus
    {
        $version = (new RowReader(['v' => $c->fetchOne('SELECT VERSION()')]))->string('v');

        return ReplicaSyntax::exists($this->engine, $version) ? null : DiagnosticStatus::unsupported('version_not_supported');
    }

    private function privilege(): string
    {
        return Engine::MariaDb === $this->engine ? 'SLAVE MONITOR' : 'REPLICATION CLIENT';
    }
}
