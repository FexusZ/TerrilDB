<?php

declare(strict_types=1);

namespace App\Driver\Redis;

use App\Core\Diagnostics\DiagnosticStatus;
use App\Core\Diagnostics\Replication\KeyValueReplication;
use App\Core\Diagnostics\Replication\ReplicaLink;
use App\Core\Diagnostics\Replication\ReplicaLinks;
use App\Core\Diagnostics\Replication\ReplicationChannel;
use App\Core\Diagnostics\Replication\ReplicationExtras;
use App\Core\Diagnostics\Replication\ReplicationReport;
use App\Core\Diagnostics\Replication\ReplicationRole;
use App\Core\Diagnostics\Replication\ReplicationSettings;
use App\Core\Diagnostics\Replication\ThreadState;

/**
 * Assemble, sans accès au serveur, la sortie de `INFO replication`. Seuls certains champs sont lus : `master_host`, `master_port`
 * (et `masterauth`, qu'INFO ne montre pas) ne sont jamais copiés, pas plus que l'adresse et le port des lignes `slaveN` : un réplica
 * n'y est qu'un rang, un état, un retard et un décalage.
 */
final class RedisReplicationReport
{
    public static function build(string $info): ReplicationReport
    {
        $fields = [];
        foreach (preg_split('/\r?\n/', $info) ?: [] as $line) {
            if ('' === $line || '#' === $line[0] || !str_contains($line, ':')) {
                continue;
            }
            [$key, $value] = explode(':', $line, 2);
            $fields[$key] = $value;
        }

        $role = $fields['role'] ?? throw new \UnexpectedValueException('INFO replication has no role.');
        $masterOffset = self::int($fields['master_repl_offset'] ?? null);
        $replicas = self::replicas($fields, $masterOffset);
        $connected = self::int($fields['connected_slaves'] ?? null) ?? \count($replicas->replicas);

        $isReplica = 'slave' === $role;
        $keyValue = new KeyValueReplication(
            $isReplica ? ($fields['master_link_status'] ?? null) : null,
            $isReplica ? self::nonNegative($fields['master_last_io_seconds_ago'] ?? null) : null,
            $isReplica && isset($fields['master_sync_in_progress']) ? '1' === $fields['master_sync_in_progress'] : null,
            self::int($fields['repl_backlog_size'] ?? null),
            $masterOffset,
        );
        $extras = new ReplicationExtras(replicas: $replicas, keyValue: $keyValue);

        if (!$isReplica) {
            return new ReplicationReport(DiagnosticStatus::available(), $connected > 0 ? ReplicationRole::Source : ReplicationRole::None, [], new ReplicationSettings(null, null, null, null), $connected, $extras);
        }

        $received = $fields['slave_read_repl_offset'] ?? null;
        $applied = $fields['slave_repl_offset'] ?? null;
        $receiver = match (true) {
            'up' === ($fields['master_link_status'] ?? null) => ThreadState::Running,
            '1' === ($fields['master_sync_in_progress'] ?? null) => ThreadState::Connecting,
            default => ThreadState::Stopped,
        };
        // Redis applique ce qu'il reçoit : pas de retard en secondes à annoncer (reportsLag faux), le silence de la source est dans `keyValue`.
        $channel = new ReplicationChannel('', $receiver, ThreadState::Running, null, $received, $applied, null === $received || null === $applied ? null : $received !== $applied, null, null, false);

        return new ReplicationReport(
            DiagnosticStatus::available(),
            ReplicationRole::Replica,
            [$channel],
            new ReplicationSettings(isset($fields['slave_read_only']) ? '1' === $fields['slave_read_only'] : null, null, null, null),
            $connected,
            $extras,
        );
    }

    /**
     * @param array<string, string> $fields
     */
    private static function replicas(array $fields, ?int $masterOffset): ReplicaLinks
    {
        $links = [];
        for ($i = 0; isset($fields['slave'.$i]); ++$i) {
            $parts = [];
            foreach (explode(',', $fields['slave'.$i]) as $pair) {
                if (str_contains($pair, '=')) {
                    [$k, $v] = explode('=', $pair, 2);
                    $parts[$k] = $v;
                }
            }
            $state = $parts['state'] ?? null;
            $offset = self::int($parts['offset'] ?? null);
            $lag = self::nonNegative($parts['lag'] ?? null);
            $links[] = new ReplicaLink(
                $i + 1,
                $state,
                'online' === $state,
                null,
                null,
                null,
                null === $lag ? null : (float) $lag,
                null === $masterOffset || null === $offset ? null : max(0, $masterOffset - $offset),
            );
        }

        return new ReplicaLinks(DiagnosticStatus::available(), $links);
    }

    private static function int(?string $value): ?int
    {
        return null !== $value && 1 === preg_match('/^-?\d+$/D', $value) ? (int) $value : null;
    }

    private static function nonNegative(?string $value): ?int
    {
        $int = self::int($value);

        return null !== $int && $int >= 0 ? $int : null;
    }
}
