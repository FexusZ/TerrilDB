<?php

declare(strict_types=1);

namespace App\Driver\PostgreSql;

use App\Core\Diagnostics\DiagnosticStatus;
use App\Core\Diagnostics\Replication\ReplicaLink;
use App\Core\Diagnostics\Replication\ReplicaLinks;
use App\Core\Diagnostics\Replication\ReplicationSlot;
use App\Core\Diagnostics\Replication\ReplicationSlots;
use App\Driver\RowReader;

/**
 * Assemble, sans accès au serveur, les lignes de `pg_replication_slots` et de `pg_stat_replication` que relève
 * PostgreSqlReplicationDiagnostics. Les adresses, noms de machine et comptes de `pg_stat_replication` ne sont jamais lus : un
 * réplica n'y est qu'un rang.
 */
final class PostgreSqlReplicationSections
{
    public const LIMIT = 200;

    /**
     * @param list<array<string, mixed>> $rows au plus LIMIT + 1 lignes : le surplus est écarté
     */
    public static function slots(array $rows): ReplicationSlots
    {
        $slots = [];
        foreach (\array_slice($rows, 0, self::LIMIT) as $row) {
            $row = new RowReader($row);
            $slots[] = new ReplicationSlot(
                $row->string('name'),
                $row->string('type'),
                $row->bool('active'),
                $row->bool('temporary'),
                $row->nullableInt('retained_bytes'),
                $row->nullableString('wal_status'),
                $row->nullableInt('safe_wal_size'),
            );
        }

        return new ReplicationSlots(DiagnosticStatus::available(), $slots);
    }

    /**
     * @param list<array<string, mixed>> $rows
     */
    public static function links(array $rows): ReplicaLinks
    {
        $links = [];
        foreach (\array_slice($rows, 0, self::LIMIT) as $row) {
            $reader = new RowReader($row);
            $state = $reader->nullableString('state');
            $links[] = new ReplicaLink(
                $reader->int('rank'),
                $state,
                'streaming' === $state,
                $reader->nullableString('sync_state'),
                self::seconds($row['write_lag'] ?? null),
                self::seconds($row['flush_lag'] ?? null),
                self::seconds($row['replay_lag'] ?? null),
                $reader->nullableInt('behind_bytes'),
            );
        }

        return new ReplicaLinks(DiagnosticStatus::available(), $links);
    }

    private static function seconds(mixed $value): ?float
    {
        return \is_int($value) || \is_float($value) || (\is_string($value) && is_numeric($value)) ? (float) $value : null;
    }
}
