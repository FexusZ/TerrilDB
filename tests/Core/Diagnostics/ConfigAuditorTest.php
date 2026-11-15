<?php

declare(strict_types=1);

namespace App\Tests\Core\Diagnostics;

use App\Core\Connection\Engine;
use App\Core\Diagnostics\Availability;
use App\Core\Diagnostics\ConfigAuditor;
use App\Core\Diagnostics\ConfigFinding;
use App\Core\Diagnostics\ConfigRule;
use App\Core\Diagnostics\DiagnosticStatus;
use App\Core\Diagnostics\SecretVariables;
use App\Core\Diagnostics\ServerVariable;
use App\Core\Diagnostics\Severity;
use App\Core\Diagnostics\VariableReport;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ConfigAuditorTest extends TestCase
{
    /**
     * @param array<string, string|null> $values
     */
    private static function report(array $values, ?DiagnosticStatus $status = null, bool $truncated = false): VariableReport
    {
        $variables = [];
        foreach ($values as $name => $value) {
            $variables[] = new ServerVariable($name, $value, null, false, null, null, null);
        }

        return new VariableReport($status ?? DiagnosticStatus::available(), $variables, $truncated);
    }

    /**
     * @param array<string, string|null> $values
     *
     * @return list<string> rule values
     */
    private static function rules(Engine $engine, array $values): array
    {
        return array_map(static fn (ConfigFinding $f): string => $f->rule->value, (new ConfigAuditor())->audit($engine, self::report($values))->findings);
    }

    /**
     * @param array<string, string|null> $values
     */
    private static function finding(Engine $engine, array $values, ConfigRule $rule): ConfigFinding
    {
        foreach ((new ConfigAuditor())->audit($engine, self::report($values))->findings as $finding) {
            if ($finding->rule === $rule) {
                return $finding;
            }
        }

        self::fail('No finding for '.$rule->value);
    }

    /**
     * @return iterable<string, array{Engine, array<string, string|null>, ConfigRule, Severity}>
     */
    public static function positives(): iterable
    {
        yield 'sql_mode without strict' => [Engine::MySql, ['sql_mode' => 'ONLY_FULL_GROUP_BY,NO_ENGINE_SUBSTITUTION'], ConfigRule::SqlModeNotStrict, Severity::Medium];
        yield 'sql_mode empty' => [Engine::MariaDb, ['sql_mode' => ''], ConfigRule::SqlModeNotStrict, Severity::Medium];
        yield 'local_infile on' => [Engine::MySql, ['local_infile' => 'ON'], ConfigRule::LocalInfileEnabled, Severity::Low];
        yield 'local_infile 1' => [Engine::MariaDb, ['local_infile' => '1'], ConfigRule::LocalInfileEnabled, Severity::Low];
        yield 'skip_name_resolve off' => [Engine::MySql, ['skip_name_resolve' => 'OFF'], ConfigRule::SkipNameResolveMissing, Severity::Low];
        yield 'binlog never expires (MySQL)' => [Engine::MySql, ['log_bin' => 'ON', 'binlog_expire_logs_seconds' => '0'], ConfigRule::BinlogNeverExpires, Severity::Medium];
        yield 'binlog never expires (MariaDB)' => [Engine::MariaDb, ['log_bin' => 'ON', 'binlog_expire_logs_seconds' => '0', 'expire_logs_days' => '0.000000'], ConfigRule::BinlogNeverExpires, Severity::Medium];
        yield 'flush at commit 2' => [Engine::MySql, ['innodb_flush_log_at_trx_commit' => '2'], ConfigRule::FlushLogAtCommitRelaxed, Severity::Medium];
        yield 'flush at commit 0' => [Engine::MariaDb, ['innodb_flush_log_at_trx_commit' => '0'], ConfigRule::FlushLogAtCommitRelaxed, Severity::Medium];
        yield 'sync_binlog 0' => [Engine::MySql, ['log_bin' => 'ON', 'sync_binlog' => '0'], ConfigRule::SyncBinlogRelaxed, Severity::Low];
        yield 'performance_schema off' => [Engine::MariaDb, ['performance_schema' => 'OFF'], ConfigRule::PerformanceSchemaOff, Severity::Low];
        yield 'slow log off' => [Engine::MySql, ['slow_query_log' => 'OFF'], ConfigRule::SlowQueryLogOff, Severity::Low];
        yield 'general log on' => [Engine::MySql, ['general_log' => 'ON'], ConfigRule::GeneralLogOn, Severity::Medium];
        yield 'tiny packet' => [Engine::MySql, ['max_allowed_packet' => '65536'], ConfigRule::MaxAllowedPacketTiny, Severity::Low];
        yield 'tmp above heap (MariaDB)' => [Engine::MariaDb, ['tmp_table_size' => '33554432', 'max_heap_table_size' => '16777216'], ConfigRule::TmpTableAboveHeapTable, Severity::Low];
        yield 'secure_file_priv empty (MySQL)' => [Engine::MySql, ['secure_file_priv' => ''], ConfigRule::SecureFilePrivEmpty, Severity::Medium];
        yield 'secure_file_priv empty (MariaDB)' => [Engine::MariaDb, ['secure_file_priv' => ''], ConfigRule::SecureFilePrivEmpty, Severity::Low];
        yield 'doublewrite off' => [Engine::MySql, ['innodb_doublewrite' => 'OFF'], ConfigRule::DoublewriteOff, Severity::Medium];
        yield 'fsync off' => [Engine::PostgreSql, ['fsync' => 'off'], ConfigRule::FsyncOff, Severity::High];
        yield 'full_page_writes off' => [Engine::PostgreSql, ['full_page_writes' => 'off'], ConfigRule::FullPageWritesOff, Severity::Medium];
        yield 'synchronous_commit off' => [Engine::PostgreSql, ['synchronous_commit' => 'off'], ConfigRule::SynchronousCommitOff, Severity::Low];
        yield 'no slow statement log' => [Engine::PostgreSql, ['log_min_duration_statement' => '-1'], ConfigRule::SlowStatementLogOff, Severity::Low];
        yield 'ssl off' => [Engine::PostgreSql, ['ssl' => 'off'], ConfigRule::SslOff, Severity::Medium];
        yield 'old tls' => [Engine::PostgreSql, ['ssl' => 'on', 'ssl_min_protocol_version' => 'TLSv1.1'], ConfigRule::SslMinProtocolOld, Severity::Medium];
        yield 'autovacuum off' => [Engine::PostgreSql, ['autovacuum' => 'off'], ConfigRule::AutovacuumOff, Severity::High];
        yield 'track_counts off' => [Engine::PostgreSql, ['track_counts' => 'off'], ConfigRule::TrackCountsOff, Severity::Medium];
        yield 'zero_damaged_pages on' => [Engine::PostgreSql, ['zero_damaged_pages' => 'on'], ConfigRule::ZeroDamagedPagesOn, Severity::High];
        yield 'ignore_checksum_failure on' => [Engine::PostgreSql, ['ignore_checksum_failure' => 'on'], ConfigRule::IgnoreChecksumFailureOn, Severity::High];
        yield 'checksums off' => [Engine::PostgreSql, ['data_checksums' => 'off'], ConfigRule::DataChecksumsOff, Severity::Low];
    }

    /**
     * @param array<string, string|null> $values
     */
    #[DataProvider('positives')]
    public function testARuleFiresWithItsSeverityAndAdvice(Engine $engine, array $values, ConfigRule $rule, Severity $severity): void
    {
        $finding = self::finding($engine, $values, $rule);

        self::assertSame($severity, $finding->severity);
        self::assertNotSame('', $finding->recommended);
        self::assertNotSame('', $rule->explanation());
        self::assertNotSame('', $finding->variable);
    }

    /**
     * @return iterable<string, array{Engine, array<string, string|null>}>
     */
    public static function negatives(): iterable
    {
        yield 'strict_all_tables' => [Engine::MySql, ['sql_mode' => 'STRICT_ALL_TABLES']];
        yield 'strict, any case' => [Engine::MariaDb, ['sql_mode' => 'ansi,strict_trans_tables']];
        yield 'local_infile off' => [Engine::MySql, ['local_infile' => 'OFF']];
        yield 'skip_name_resolve on' => [Engine::MySql, ['skip_name_resolve' => 'ON']];
        yield 'no binary log' => [Engine::MySql, ['log_bin' => 'OFF', 'binlog_expire_logs_seconds' => '0']];
        yield 'binlog expires' => [Engine::MySql, ['log_bin' => 'ON', 'binlog_expire_logs_seconds' => '2592000']];
        yield 'MariaDB expires by days' => [Engine::MariaDb, ['log_bin' => 'ON', 'binlog_expire_logs_seconds' => '0', 'expire_logs_days' => '10.000000']];
        yield 'MariaDB days unknown' => [Engine::MariaDb, ['log_bin' => 'ON', 'binlog_expire_logs_seconds' => '0']];
        yield 'flush at commit 1' => [Engine::MySql, ['innodb_flush_log_at_trx_commit' => '1']];
        yield 'sync_binlog 1' => [Engine::MySql, ['log_bin' => 'ON', 'sync_binlog' => '1']];
        yield 'sync_binlog 0 without binlog' => [Engine::MariaDb, ['log_bin' => 'OFF', 'sync_binlog' => '0']];
        yield 'performance_schema on' => [Engine::MySql, ['performance_schema' => 'ON']];
        yield 'slow log on' => [Engine::MySql, ['slow_query_log' => 'ON']];
        yield 'general log off' => [Engine::MySql, ['general_log' => 'OFF']];
        yield 'packet 64 MiB' => [Engine::MySql, ['max_allowed_packet' => '67108864']];
        yield 'tmp equals heap' => [Engine::MariaDb, ['tmp_table_size' => '16777216', 'max_heap_table_size' => '16777216']];
        yield 'tmp above heap on MySQL is not judged' => [Engine::MySql, ['tmp_table_size' => '33554432', 'max_heap_table_size' => '16777216']];
        yield 'secure_file_priv set' => [Engine::MySql, ['secure_file_priv' => '/var/lib/mysql-files/']];
        yield 'doublewrite on' => [Engine::MySql, ['innodb_doublewrite' => 'ON']];
        yield 'doublewrite detect_only' => [Engine::MySql, ['innodb_doublewrite' => 'DETECT_ONLY']];
        yield 'pg defaults' => [Engine::PostgreSql, ['fsync' => 'on', 'full_page_writes' => 'on', 'synchronous_commit' => 'on', 'log_min_duration_statement' => '250', 'ssl' => 'on', 'ssl_min_protocol_version' => 'TLSv1.2', 'autovacuum' => 'on', 'track_counts' => 'on', 'zero_damaged_pages' => 'off', 'ignore_checksum_failure' => 'off', 'data_checksums' => 'on']];
        yield 'synchronous_commit local' => [Engine::PostgreSql, ['synchronous_commit' => 'local']];
    }

    /**
     * @param array<string, string|null> $values
     */
    #[DataProvider('negatives')]
    public function testAHealthyValueRaisesNothing(Engine $engine, array $values): void
    {
        $rules = self::rules($engine, $values);

        self::assertSame([], $rules);
    }

    public function testAnOldTlsFloorIsNotJudgedWhileSslIsOff(): void
    {
        self::assertSame([ConfigRule::SslOff->value], self::rules(Engine::PostgreSql, ['ssl' => 'off', 'ssl_min_protocol_version' => 'TLSv1']));
    }

    public function testRulesOfAnotherEngineAreNotApplied(): void
    {
        self::assertSame([], self::rules(Engine::MySql, ['fsync' => 'off', 'autovacuum' => 'off']));
        self::assertSame([], self::rules(Engine::PostgreSql, ['local_infile' => 'ON', 'general_log' => 'ON']));
    }

    public function testAMaskedOrMissingOrUnreadableVariableSaysNothing(): void
    {
        self::assertSame([], self::rules(Engine::MySql, ['general_log' => null]));
        self::assertSame([], self::rules(Engine::MySql, []));
        $masked = new VariableReport(DiagnosticStatus::available(), [new ServerVariable('general_log', 'password=hunter2', null, false, null, null, null)], false);

        self::assertSame([], (new ConfigAuditor())->audit(Engine::MySql, $masked)->findings);
    }

    public function testNumbersThatAreNotNumbersAreNotJudged(): void
    {
        self::assertSame([], self::rules(Engine::MySql, ['max_allowed_packet' => 'abc', 'innodb_flush_log_at_trx_commit' => 'x']));
        self::assertSame([], self::rules(Engine::MariaDb, ['tmp_table_size' => 'x', 'max_heap_table_size' => '1']));
    }

    public function testEvaluatedCountsOnlyTheRulesThatCouldBeRead(): void
    {
        $none = (new ConfigAuditor())->audit(Engine::MySql, self::report([]));
        $some = (new ConfigAuditor())->audit(Engine::MySql, self::report(['general_log' => 'OFF', 'local_infile' => 'OFF', 'sql_mode' => null]));

        self::assertSame(0, $none->evaluated);
        self::assertSame(2, $some->evaluated);
        self::assertGreaterThan(10, $none->rulesTotal);
    }

    public function testAnUnavailableSourceGivesItsStatusAndNoFinding(): void
    {
        $report = (new ConfigAuditor())->audit(Engine::MySql, self::report(['general_log' => 'ON'], DiagnosticStatus::disabled('performance_schema_off')));

        self::assertSame(Availability::Disabled, $report->status->availability);
        self::assertSame([], $report->findings);
        $denied = (new ConfigAuditor())->audit(Engine::PostgreSql, self::report([], DiagnosticStatus::denied('SELECT ON system.server_settings')));
        self::assertSame(Availability::PermissionDenied, $denied->status->availability);
    }

    public function testEnginesWithoutRulesAreUnsupported(): void
    {
        foreach ([Engine::ClickHouse, Engine::Redis] as $engine) {
            $report = (new ConfigAuditor())->audit($engine, self::report(['x' => '1']));

            self::assertSame(Availability::Unsupported, $report->status->availability);
            self::assertSame('no_rules', $report->status->reason);
            self::assertSame([], ConfigAuditor::namesFor($engine));
        }
    }

    public function testNamesAreTheVariablesTheRulesRead(): void
    {
        self::assertContains('sql_mode', ConfigAuditor::namesFor(Engine::MySql));
        self::assertContains('sql_mode', ConfigAuditor::namesFor(Engine::MariaDb));
        self::assertContains('fsync', ConfigAuditor::namesFor(Engine::PostgreSql));
        self::assertNotContains('fsync', ConfigAuditor::namesFor(Engine::MySql));
    }

    public function testFindingsAreSortedBySeverityThenRule(): void
    {
        $findings = (new ConfigAuditor())->audit(Engine::PostgreSql, self::report(['synchronous_commit' => 'off', 'fsync' => 'off', 'ssl' => 'off']))->findings;

        self::assertSame(['fsync', 'ssl', 'synchronous_commit'], array_map(static fn (ConfigFinding $f): string => $f->variable, $findings));
    }

    public function testSuggestionsAreTextToReadFixedByTheRule(): void
    {
        $mysql = self::finding(Engine::MySql, ['general_log' => 'ON'], ConfigRule::GeneralLogOn);
        $maria = self::finding(Engine::MariaDb, ['general_log' => 'ON'], ConfigRule::GeneralLogOn);
        $pg = self::finding(Engine::PostgreSql, ['fsync' => 'off'], ConfigRule::FsyncOff);
        $static = self::finding(Engine::MySql, ['performance_schema' => 'OFF'], ConfigRule::PerformanceSchemaOff);

        self::assertSame('SET PERSIST general_log = OFF;', $mysql->suggestion);
        self::assertSame('SET GLOBAL general_log = OFF;', $maria->suggestion);
        self::assertSame("ALTER SYSTEM SET fsync = 'on'; SELECT pg_reload_conf();", $pg->suggestion);
        self::assertNull($static->suggestion, 'A static variable is changed in the configuration file.');
    }

    public function testTheValueSeenIsKept(): void
    {
        self::assertSame('2', self::finding(Engine::MySql, ['innodb_flush_log_at_trx_commit' => '2'], ConfigRule::FlushLogAtCommitRelaxed)->value);
    }

    public function testNoRuleReadsAVariableThatIsMasked(): void
    {
        foreach ([Engine::MySql, Engine::MariaDb, Engine::PostgreSql] as $engine) {
            foreach (ConfigAuditor::namesFor($engine) as $name) {
                self::assertFalse(SecretVariables::matches($name), $name.' would never be readable.');
            }
        }
    }

    public function testEveryRuleBelongsToAnEngineAndSpeaks(): void
    {
        $seen = [];
        foreach ([Engine::MySql, Engine::MariaDb, Engine::PostgreSql] as $engine) {
            foreach (ConfigRule::forEngine($engine) as $rule) {
                $seen[$rule->value] = $rule->explanation();
            }
        }

        self::assertCount(\count(ConfigRule::cases()), $seen);
        self::assertNotContains('', $seen);
    }
}
