<?php

declare(strict_types=1);

namespace App\Driver\MySql;

use App\Core\Connection\Engine;
use App\Core\Diagnostics\DiagnosticStatus;
use App\Core\Diagnostics\Replication\BinlogState;
use App\Core\Diagnostics\Replication\SemiSyncMode;
use App\Core\Diagnostics\Replication\SemiSyncState;
use App\Driver\RowReader;

/**
 * Assemble, sans accès au serveur, les journaux binaires et l'état semi-synchrone que relève MySqlReplicationDiagnostics, à partir
 * de variables et de compteurs globaux (nom => valeur) et de `SHOW BINARY LOGS`. MySQL et MariaDB n'écrivent pas pareil : MariaDB
 * garde les noms `master` / `slave` du greffon semi-synchrone (MySQL 8.4 : `source` / `replica`) et `gtid_binlog_pos` pour les GTID.
 */
final class MySqlReplicationSections
{
    public const GTID_MAX_LENGTH = 500;

    /**
     * @param array<string, string>           $variables
     * @param list<array<string, mixed>>|null $logs      null : le journal binaire est éteint, `SHOW BINARY LOGS` n'a pas été lu
     */
    public static function binlog(Engine $engine, array $variables, ?array $logs): BinlogState
    {
        $enabled = self::on($variables['log_bin'] ?? null);
        $gtid = self::gtid($variables[Engine::MariaDb === $engine ? 'gtid_binlog_pos' : 'gtid_executed'] ?? null);
        if (!$enabled) {
            return new BinlogState(DiagnosticStatus::disabled('binary_log_off'), false, null, null, self::expiry($variables), $gtid, null);
        }

        $files = null === $logs ? null : \count($logs);
        $bytes = null === $logs ? null : array_sum(array_map(static fn (array $l): int => (new RowReader($l))->int('File_size'), $logs));

        return new BinlogState(DiagnosticStatus::available(), true, $files, $bytes, self::expiry($variables), $gtid, self::gtid($variables['gtid_purged'] ?? null));
    }

    /**
     * @param array<string, string> $variables
     * @param array<string, string> $status
     */
    public static function semiSync(array $variables, array $status): SemiSyncState
    {
        $source = self::mode($variables, $status, ['rpl_semi_sync_source_enabled', 'rpl_semi_sync_master_enabled'], ['Rpl_semi_sync_source_status', 'Rpl_semi_sync_master_status'], true);
        // Côté réplica, « activé mais pas semi-synchrone » est l'état normal d'un fil d'E/S arrêté ou d'une source qui ne l'est pas : jamais un repli.
        $replica = self::mode($variables, $status, ['rpl_semi_sync_replica_enabled', 'rpl_semi_sync_slave_enabled'], ['Rpl_semi_sync_replica_status', 'Rpl_semi_sync_slave_status'], false);
        if (SemiSyncMode::NotLoaded === $source && SemiSyncMode::NotLoaded === $replica) {
            return new SemiSyncState(DiagnosticStatus::disabled('semi_sync_not_loaded'), $source, $replica, null);
        }

        $clients = $status['Rpl_semi_sync_source_clients'] ?? $status['Rpl_semi_sync_master_clients'] ?? null;

        return new SemiSyncState(DiagnosticStatus::available(), $source, $replica, null !== $clients && 1 === preg_match('/^\d+$/D', $clients) ? (int) $clients : null);
    }

    /**
     * @param array<string, string> $variables
     * @param array<string, string> $status
     * @param list<string>          $enabledNames
     * @param list<string>          $statusNames
     */
    private static function mode(array $variables, array $status, array $enabledNames, array $statusNames, bool $reportsFallback): SemiSyncMode
    {
        $enabled = null;
        foreach ($enabledNames as $name) {
            $enabled ??= $variables[$name] ?? null;
        }
        if (null === $enabled) {
            return SemiSyncMode::NotLoaded;
        }
        if (!self::on($enabled)) {
            return SemiSyncMode::Off;
        }

        $current = null;
        foreach ($statusNames as $name) {
            $current ??= $status[$name] ?? null;
        }

        return self::on($current) ? SemiSyncMode::Active : ($reportsFallback ? SemiSyncMode::FallenBack : SemiSyncMode::Off);
    }

    /**
     * Secondes avant l'expiration d'un journal : 0 quand rien n'expire jamais (purge automatique éteinte ou délai nul), null
     * quand le serveur ne le dit pas. `binlog_expire_logs_seconds` prime, `expire_logs_days` (retiré de MySQL 8.4) sert de repli.
     *
     * @param array<string, string> $variables
     */
    private static function expiry(array $variables): ?int
    {
        if (isset($variables['binlog_expire_logs_auto_purge']) && !self::on($variables['binlog_expire_logs_auto_purge'])) {
            return 0;
        }
        $seconds = isset($variables['binlog_expire_logs_seconds']) && is_numeric($variables['binlog_expire_logs_seconds']) ? (int) $variables['binlog_expire_logs_seconds'] : null;
        $days = isset($variables['expire_logs_days']) && is_numeric($variables['expire_logs_days']) ? (float) $variables['expire_logs_days'] : null;
        if (null !== $seconds && $seconds > 0) {
            return $seconds;
        }
        if (null !== $days && $days > 0) {
            return (int) round($days * 86400);
        }

        return null === $seconds && null === $days ? null : 0;
    }

    private static function gtid(?string $set): ?string
    {
        $set = trim((string) preg_replace('/\s+/', '', (string) $set));
        if ('' === $set) {
            return null;
        }

        return mb_strlen($set) > self::GTID_MAX_LENGTH ? mb_substr($set, 0, self::GTID_MAX_LENGTH).'…' : $set;
    }

    private static function on(?string $value): bool
    {
        return null !== $value && \in_array(strtoupper($value), ['ON', '1', 'YES', 'TRUE'], true);
    }
}
