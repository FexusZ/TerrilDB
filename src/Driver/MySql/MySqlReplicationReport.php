<?php

declare(strict_types=1);

namespace App\Driver\MySql;

use App\Core\Connection\Engine;
use App\Core\Diagnostics\DiagnosticStatus;
use App\Core\Diagnostics\Replication\ReplicationChannel;
use App\Core\Diagnostics\Replication\ReplicationError;
use App\Core\Diagnostics\Replication\ReplicationReport;
use App\Core\Diagnostics\Replication\ReplicationRole;
use App\Core\Diagnostics\Replication\ReplicationSettings;
use App\Core\Diagnostics\Replication\ThreadState;
use App\Driver\RowReader;

/**
 * Assemble, sans accès au serveur, ce que `SHOW REPLICA STATUS` et quelques variables ont rendu. MySQL et MariaDB disent la même
 * chose avec d'autres noms de colonnes (`Replica_IO_Running` / `Slave_IO_Running`, `Seconds_Behind_Source` / `…_Master`) : le premier
 * nom présent est lu. L'hôte, le compte et le port de la source ne sont jamais copiés.
 */
final class MySqlReplicationReport
{
    private const GTID_LIMIT = 2000;

    /**
     * @param list<array<string, mixed>>      $statusRows   lignes de SHOW REPLICA STATUS (une par canal)
     * @param array<string, string>           $variables    variables globales lues (read_only, super_read_only, binlog_format, gtid_mode, gtid_slave_pos)
     * @param ?int                            $replicaCount nombre de lignes de SHOW REPLICAS (null : non lisible)
     * @param array<string, string>           $unapplied    par canal : GTID reçus et pas encore appliqués (MySQL, GTID_SUBTRACT) ; vide = tout est appliqué
     * @param array<string, ReplicationError> $workerErrors par canal : la dernière erreur du fil applicateur (plus parlante que celle du coordinateur)
     */
    public static function build(Engine $engine, array $statusRows, array $variables, ?int $replicaCount, array $unapplied, array $workerErrors): ReplicationReport
    {
        $mariaDb = Engine::MariaDb === $engine;
        $channels = [];
        foreach ($statusRows as $row) {
            $channels[] = self::channel($mariaDb, new RowReader($row), $row, $variables, $unapplied, $workerErrors);
        }

        $role = match (true) {
            [] !== $channels => ReplicationRole::Replica,
            null !== $replicaCount && $replicaCount > 0 => ReplicationRole::Source,
            default => ReplicationRole::None,
        };

        $gtid = $mariaDb
            ? ('No' === ($statusRows[0]['Using_Gtid'] ?? null) ? false : true)
            : (isset($variables['gtid_mode']) ? 'ON' === strtoupper($variables['gtid_mode']) : null);

        return new ReplicationReport(
            DiagnosticStatus::available(),
            $role,
            $channels,
            new ReplicationSettings(self::flag($variables['read_only'] ?? null), self::flag($variables['super_read_only'] ?? null), $variables['binlog_format'] ?? null, $gtid),
            $replicaCount,
        );
    }

    /**
     * @param array<string, mixed>            $raw
     * @param array<string, string>           $variables
     * @param array<string, string>           $unapplied
     * @param array<string, ReplicationError> $workerErrors
     */
    private static function channel(bool $mariaDb, RowReader $row, array $raw, array $variables, array $unapplied, array $workerErrors): ReplicationChannel
    {
        $pick = static fn (string $mysql, string $maria): string => \array_key_exists($mysql, $raw) ? $mysql : $maria;
        $name = $mariaDb ? ($row->nullableString('Connection_name') ?? '') : ($row->nullableString('Channel_Name') ?? '');

        if ($mariaDb) {
            $received = self::gtidSet($row->nullableString('Gtid_IO_Pos'));
            $applied = self::gtidSet($variables['gtid_slave_pos'] ?? null);
            $pending = null === $received || null === ($variables['gtid_slave_pos'] ?? null) ? null : $received !== $applied;
        } else {
            $received = self::gtidSet($row->nullableString('Retrieved_Gtid_Set'));
            $applied = self::gtidSet($row->nullableString('Executed_Gtid_Set'));
            $pending = \array_key_exists($name, $unapplied) ? '' !== trim($unapplied[$name]) : null;
        }

        $sqlError = ReplicationError::from($row->nullableInt('Last_SQL_Errno'), $row->nullableString('Last_SQL_Error'));

        return new ReplicationChannel(
            $name,
            self::thread($row->nullableString($pick('Replica_IO_Running', 'Slave_IO_Running'))),
            self::thread($row->nullableString($pick('Replica_SQL_Running', 'Slave_SQL_Running'))),
            $row->nullableInt($pick('Seconds_Behind_Source', 'Seconds_Behind_Master')),
            $received,
            $applied,
            $pending,
            ReplicationError::from($row->nullableInt('Last_IO_Errno'), $row->nullableString('Last_IO_Error')),
            null === $sqlError ? null : ($workerErrors[$name] ?? $sqlError),
        );
    }

    private static function thread(?string $value): ThreadState
    {
        return match (strtolower($value ?? '')) {
            'yes' => ThreadState::Running,
            'connecting' => ThreadState::Connecting,
            default => ThreadState::Stopped,
        };
    }

    private static function flag(?string $value): ?bool
    {
        return match (strtoupper($value ?? '')) {
            'ON', '1' => true,
            'OFF', '0' => false,
            default => null,
        };
    }

    private static function gtidSet(?string $set): ?string
    {
        if (null === $set || '' === trim($set)) {
            return null;
        }
        $set = (string) preg_replace('/,\s*/', ', ', trim($set));

        return mb_strlen($set) > self::GTID_LIMIT ? mb_substr($set, 0, self::GTID_LIMIT).'…' : $set;
    }
}
