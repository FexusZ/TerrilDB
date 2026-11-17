<?php

declare(strict_types=1);

namespace App\Core\Diagnostics;

use App\Core\Connection\Engine;

/**
 * Les règles d'audit de configuration, pures : elles ne lisent que les variables déjà lues, ne se taisent jamais à tort (une variable
 * masquée, absente ou illisible n'est PAS jugée) et ne suggèrent qu'un texte à relire. Chaque règle est justifiée par la documentation
 * du moteur et vérifiée sur ses cibles de test ; la RAM et les compteurs d'état n'étant pas lus, rien ne juge une taille de cache ni
 * un plafond de connexions « atteint ».
 */
final class ConfigAuditor
{
    private const MYSQL_NAMES = [
        'sql_mode', 'local_infile', 'skip_name_resolve', 'log_bin', 'binlog_expire_logs_seconds', 'expire_logs_days',
        'innodb_flush_log_at_trx_commit', 'sync_binlog', 'performance_schema', 'slow_query_log', 'general_log',
        'max_allowed_packet', 'tmp_table_size', 'max_heap_table_size', 'secure_file_priv', 'innodb_doublewrite',
    ];
    private const POSTGRESQL_NAMES = [
        'fsync', 'full_page_writes', 'synchronous_commit', 'log_min_duration_statement', 'ssl', 'ssl_min_protocol_version',
        'autovacuum', 'track_counts', 'zero_damaged_pages', 'ignore_checksum_failure', 'data_checksums',
    ];
    private const MIN_PACKET = 1_048_576;

    /**
     * Les variables que les règles du moteur lisent (de quoi les demander exactement, sans lister tout le serveur).
     *
     * @return list<string>
     */
    public static function namesFor(Engine $engine): array
    {
        return match ($engine) {
            Engine::MySql, Engine::MariaDb => self::MYSQL_NAMES,
            Engine::PostgreSql => self::POSTGRESQL_NAMES,
            default => [],
        };
    }

    public function audit(Engine $engine, VariableReport $report): ConfigAuditReport
    {
        $rules = ConfigRule::forEngine($engine);
        if ([] === $rules) {
            return new ConfigAuditReport($report->status->isAvailable() ? DiagnosticStatus::unsupported('no_rules') : $report->status, $engine, [], 0, 0);
        }
        if (!$report->status->isAvailable()) {
            return new ConfigAuditReport($report->status, $engine, [], 0, \count($rules));
        }

        $values = [];
        foreach ($report->variables as $variable) {
            if (!$variable->masked && null !== $variable->value) {
                $values[$variable->name] = $variable->value;
            }
        }
        $probe = new ConfigProbe($engine, $values);
        if (Engine::PostgreSql === $engine) {
            $this->postgreSql($probe);
        } else {
            $this->mySql($probe);
        }

        $findings = $probe->findings;
        usort($findings, static fn (ConfigFinding $a, ConfigFinding $b): int => [$a->severity->rank(), $a->variable, $a->rule->value] <=> [$b->severity->rank(), $b->variable, $b->rule->value]);

        return new ConfigAuditReport($report->status, $engine, $findings, $probe->evaluated, \count($rules));
    }

    private function mySql(ConfigProbe $p): void
    {
        $maria = Engine::MariaDb === $p->engine;

        $p->when(ConfigRule::SqlModeNotStrict, 'sql_mode', static fn (string $v): bool => [] === array_intersect(['STRICT_TRANS_TABLES', 'STRICT_ALL_TABLES'], array_map('trim', explode(',', strtoupper($v)))), Severity::Medium, 'STRICT_TRANS_TABLES', "SET GLOBAL sql_mode = CONCAT_WS(',', NULLIF(@@GLOBAL.sql_mode, ''), 'STRICT_TRANS_TABLES');");
        $p->when(ConfigRule::LocalInfileEnabled, 'local_infile', ConfigProbe::isOn(...), Severity::Low, 'OFF', 'OFF');
        $p->when(ConfigRule::SkipNameResolveMissing, 'skip_name_resolve', ConfigProbe::isOff(...), Severity::Low, 'ON', null);
        $p->when(ConfigRule::FlushLogAtCommitRelaxed, 'innodb_flush_log_at_trx_commit', static fn (string $v): bool => ctype_digit($v) && 1 !== (int) $v, Severity::Medium, '1', '1');
        $p->when(ConfigRule::PerformanceSchemaOff, 'performance_schema', ConfigProbe::isOff(...), Severity::Low, 'ON', null);
        $p->when(ConfigRule::SlowQueryLogOff, 'slow_query_log', ConfigProbe::isOff(...), Severity::Low, 'ON', 'ON');
        $p->when(ConfigRule::GeneralLogOn, 'general_log', ConfigProbe::isOn(...), Severity::Medium, 'OFF', 'OFF');
        $p->when(ConfigRule::MaxAllowedPacketTiny, 'max_allowed_packet', static fn (string $v): bool => ctype_digit($v) && (int) $v < self::MIN_PACKET, Severity::Low, '>= 1048576 (par exemple 16777216)', '16777216');
        $p->when(ConfigRule::SecureFilePrivEmpty, 'secure_file_priv', static fn (string $v): bool => '' === $v, $maria ? Severity::Low : Severity::Medium, 'un répertoire dédié', null);
        $p->when(ConfigRule::DoublewriteOff, 'innodb_doublewrite', ConfigProbe::isOff(...), Severity::Medium, 'ON', 'ON');

        // Les règles du journal binaire ne comptent que s'il est actif.
        $binlog = $p->get('log_bin');
        if (null !== $binlog) {
            $on = ConfigProbe::isOn($binlog);
            $seconds = $p->get('binlog_expire_logs_seconds');
            $days = $p->get('expire_logs_days');
            // MySQL 8.4 n'a plus expire_logs_days ; MariaDB l'a toujours et l'une OU l'autre purge : sans lui, on ne sait pas.
            if (null !== $seconds && (null !== $days || !$maria)) {
                ++$p->evaluated;
                if ($on && ctype_digit($seconds) && 0 === (int) $seconds && (null === $days || (is_numeric($days) && 0.0 === (float) $days))) {
                    $p->add(ConfigRule::BinlogNeverExpires, Severity::Medium, 'binlog_expire_logs_seconds', $seconds, 'une durée non nulle (par exemple 604800)', '604800');
                }
            }
            $sync = $p->get('sync_binlog');
            if (null !== $sync) {
                ++$p->evaluated;
                if ($on && ctype_digit($sync) && 1 !== (int) $sync) {
                    $p->add(ConfigRule::SyncBinlogRelaxed, Severity::Low, 'sync_binlog', $sync, '1', '1');
                }
            }
        }

        if ($maria) {
            $tmp = $p->get('tmp_table_size');
            $heap = $p->get('max_heap_table_size');
            if (null !== $tmp && null !== $heap) {
                ++$p->evaluated;
                if (ctype_digit($tmp) && ctype_digit($heap) && (int) $tmp > (int) $heap) {
                    $p->add(ConfigRule::TmpTableAboveHeapTable, Severity::Low, 'tmp_table_size', $tmp, 'tmp_table_size <= max_heap_table_size ('.$heap.')', $heap);
                }
            }
        }
    }

    private function postgreSql(ConfigProbe $p): void
    {
        $p->when(ConfigRule::FsyncOff, 'fsync', ConfigProbe::isOff(...), Severity::High, 'on', 'on');
        $p->when(ConfigRule::FullPageWritesOff, 'full_page_writes', ConfigProbe::isOff(...), Severity::Medium, 'on', 'on');
        $p->when(ConfigRule::SynchronousCommitOff, 'synchronous_commit', static fn (string $v): bool => 'off' === strtolower($v), Severity::Low, 'on (ou local)', 'on');
        $p->when(ConfigRule::SlowStatementLogOff, 'log_min_duration_statement', static fn (string $v): bool => '-1' === $v, Severity::Low, 'un seuil en millisecondes (par exemple 250)', '250');
        $p->when(ConfigRule::SslOff, 'ssl', ConfigProbe::isOff(...), Severity::Medium, 'on (avec certificat et clé du serveur)', null);
        $p->when(ConfigRule::AutovacuumOff, 'autovacuum', ConfigProbe::isOff(...), Severity::High, 'on', 'on');
        $p->when(ConfigRule::TrackCountsOff, 'track_counts', ConfigProbe::isOff(...), Severity::Medium, 'on', 'on');
        $p->when(ConfigRule::ZeroDamagedPagesOn, 'zero_damaged_pages', ConfigProbe::isOn(...), Severity::High, 'off', 'off');
        $p->when(ConfigRule::IgnoreChecksumFailureOn, 'ignore_checksum_failure', ConfigProbe::isOn(...), Severity::High, 'off', 'off');
        $p->when(ConfigRule::DataChecksumsOff, 'data_checksums', ConfigProbe::isOff(...), Severity::Low, 'on (pg_checksums --enable, serveur arrêté)', null);

        $ssl = $p->get('ssl');
        $floor = $p->get('ssl_min_protocol_version');
        if (null !== $ssl && null !== $floor) {
            ++$p->evaluated;
            if (ConfigProbe::isOn($ssl) && \in_array(strtolower($floor), ['tlsv1', 'tlsv1.0', 'tlsv1.1'], true)) {
                $p->add(ConfigRule::SslMinProtocolOld, Severity::Medium, 'ssl_min_protocol_version', $floor, 'TLSv1.2', 'TLSv1.2');
            }
        }
    }
}
