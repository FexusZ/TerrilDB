<?php

declare(strict_types=1);

namespace App\Tests\Core\Optimizer;

use App\Core\Connection\Engine;
use App\Core\Optimizer\Finding;
use App\Core\Optimizer\FindingRule;
use App\Core\Optimizer\SchemaAuditor;
use App\Core\Optimizer\Severity;
use App\Core\Schema\DefinerKind;
use App\Core\Schema\DefinerObject;
use App\Core\Schema\DefinerReport;
use App\Core\Schema\TableUpkeep;
use App\Core\Schema\UpkeepReport;
use PHPUnit\Framework\TestCase;

/**
 * Les règles d'exploitation lues dans les compteurs et le catalogue : pures, prudentes, sans rien exécuter.
 */
final class OperationsAuditTest extends TestCase
{
    /**
     * @return list<Finding>
     */
    private function upkeep(Engine $engine, TableUpkeep ...$tables): array
    {
        return (new SchemaAuditor())->operations($engine, UpkeepReport::of(array_values($tables)), null);
    }

    public function testFlagsStaleStatisticsWhenManyRowsChangedSinceTheLastAnalyze(): void
    {
        $findings = $this->upkeep(Engine::PostgreSql, new TableUpkeep('orders', liveRows: 100000, modifiedSinceAnalyze: 40000, lastAnalyze: '2026-10-01 03:00:00'));

        self::assertCount(1, $findings);
        self::assertSame(FindingRule::StaleStatistics, $findings[0]->rule);
        self::assertSame(Severity::Info, $findings[0]->severity);
        self::assertSame('orders', $findings[0]->table);
        self::assertSame('40000 lignes modifiées depuis la dernière analyse (2026-10-01 03:00:00)', $findings[0]->detail);
        self::assertSame('ANALYZE "orders";', $findings[0]->suggestion);
    }

    public function testNeverAnalyzedIsSaidPlainly(): void
    {
        $findings = $this->upkeep(Engine::PostgreSql, new TableUpkeep('orders', liveRows: 20000, modifiedSinceAnalyze: 20000));

        self::assertSame('20000 lignes modifiées depuis la dernière analyse (jamais analysée depuis le début des compteurs)', $findings[0]->detail);
    }

    public function testStaleStatisticsNeedBothAnAbsoluteAndARelativeThreshold(): void
    {
        self::assertSame([], $this->upkeep(Engine::PostgreSql, new TableUpkeep('small', liveRows: 100, modifiedSinceAnalyze: 9999)));
        self::assertSame([], $this->upkeep(Engine::PostgreSql, new TableUpkeep('big', liveRows: 10000000, modifiedSinceAnalyze: 1000000)));
        self::assertSame([], $this->upkeep(Engine::PostgreSql, new TableUpkeep('unknown', liveRows: 100000)));
    }

    public function testFlagsDeadTuplesWellBeyondWhatAutovacuumTolerates(): void
    {
        $findings = $this->upkeep(Engine::PostgreSql, new TableUpkeep('events', liveRows: 50000, deadRows: 30000, lastVacuum: '2026-09-01 02:00:00'));

        self::assertCount(1, $findings);
        self::assertSame(FindingRule::DeadTuples, $findings[0]->rule);
        self::assertSame('30000 tuples morts pour 50000 vivants (dernier vacuum : 2026-09-01 02:00:00)', $findings[0]->detail);
        self::assertStringStartsWith('VACUUM (ANALYZE) "events";', (string) $findings[0]->suggestion);
        self::assertStringContainsString('-- VACUUM FULL', (string) $findings[0]->suggestion);
        self::assertStringContainsString('verrou exclusif', (string) $findings[0]->suggestion);
    }

    public function testDeadTuplesBelowTheThresholdsAreIgnored(): void
    {
        self::assertSame([], $this->upkeep(Engine::PostgreSql, new TableUpkeep('a', liveRows: 100, deadRows: 9999)));
        self::assertSame([], $this->upkeep(Engine::PostgreSql, new TableUpkeep('b', liveRows: 1000000, deadRows: 100000)));
    }

    public function testNeverVacuumedIsSaidPlainly(): void
    {
        $findings = $this->upkeep(Engine::PostgreSql, new TableUpkeep('events', liveRows: 10000, deadRows: 10000));

        self::assertSame('10000 tuples morts pour 10000 vivants (jamais vacuumée depuis le début des compteurs)', $findings[0]->detail);
    }

    public function testFlagsReclaimableSpaceOnlyWhenBigAndAShareOfTheFile(): void
    {
        $mib = 1048576;
        $findings = $this->upkeep(Engine::MariaDb, new TableUpkeep('logs', dataBytes: 20 * $mib, indexBytes: 0, freeBytes: 100 * $mib));

        self::assertCount(1, $findings);
        self::assertSame(FindingRule::ReclaimableSpace, $findings[0]->rule);
        self::assertSame('environ 100 Mio libres dans le fichier de la table (83 % de sa taille)', $findings[0]->detail);
        self::assertStringStartsWith('OPTIMIZE TABLE `logs`;', (string) $findings[0]->suggestion);
        self::assertStringContainsString('-- reconstruit', (string) $findings[0]->suggestion);

        self::assertSame([], $this->upkeep(Engine::MySql, new TableUpkeep('t', dataBytes: 20 * $mib, freeBytes: 63 * $mib)), 'under 64 MiB');
        self::assertSame([], $this->upkeep(Engine::MySql, new TableUpkeep('t', dataBytes: 1000 * $mib, indexBytes: 0, freeBytes: 100 * $mib)), 'under 30 %');
        self::assertSame([], $this->upkeep(Engine::MySql, new TableUpkeep('t', dataBytes: 1, freeBytes: null)));
    }

    public function testEachEngineOnlyGetsItsOwnRules(): void
    {
        $mib = 1048576;
        self::assertSame([], $this->upkeep(Engine::PostgreSql, new TableUpkeep('t', freeBytes: 500 * $mib, dataBytes: 1)));
        self::assertSame([], $this->upkeep(Engine::MySql, new TableUpkeep('t', liveRows: 20000, deadRows: 20000, modifiedSinceAnalyze: 20000)));
        self::assertSame([], $this->upkeep(Engine::ClickHouse, new TableUpkeep('t', liveRows: 20000, deadRows: 20000)));
    }

    public function testAnUnreadableSourceIsNeverAFindingButIsReportedAsUnchecked(): void
    {
        $auditor = new SchemaAuditor();
        $denied = UpkeepReport::unavailable('permission_denied');

        self::assertSame([], $auditor->operations(Engine::PostgreSql, $denied, null));
        $checks = $auditor->operationsChecks(Engine::PostgreSql, $denied, null);
        self::assertSame([FindingRule::StaleStatistics, FindingRule::DeadTuples], array_map(static fn ($c): FindingRule => $c->rule, $checks));
        self::assertSame('permission_denied', $checks[0]->reason);

        self::assertSame([FindingRule::ReclaimableSpace], array_map(static fn ($c): FindingRule => $c->rule, $auditor->operationsChecks(Engine::MariaDb, $denied, null)));
        self::assertSame([], $auditor->operationsChecks(Engine::PostgreSql, UpkeepReport::of([]), null));
        self::assertSame([], $auditor->operationsChecks(Engine::ClickHouse, $denied, null));
        self::assertSame([], $auditor->operationsChecks(Engine::MySql, UpkeepReport::unavailable('not_supported'), null));
    }

    public function testFlagsObjectsWhoseDefinerNoLongerExists(): void
    {
        $report = DefinerReport::of([
            new DefinerObject(DefinerKind::View, 'v_totals', null, 'gone@%'),
            new DefinerObject(DefinerKind::Event, 'nightly', null, 'gone@%'),
            new DefinerObject(DefinerKind::Trigger, 'trg_audit', 'orders', 'gone@localhost'),
            new DefinerObject(DefinerKind::Procedure, 'refresh', null, 'gone@%'),
            new DefinerObject(DefinerKind::Function, 'total', null, 'gone@%'),
        ]);

        $findings = (new SchemaAuditor())->operations(Engine::MariaDb, null, $report);

        self::assertSame(['nightly', 'refresh', 'total', 'trg_audit', 'v_totals'], array_map(static fn (Finding $f): string => $f->index ?? '', $findings));
        self::assertSame(FindingRule::OrphanDefiner, $findings[0]->rule);
        self::assertSame(Severity::Warning, $findings[0]->severity);
        self::assertSame('nightly', $findings[0]->table);
        self::assertSame('événement créé par « gone@% », compte inexistant', $findings[0]->detail);
        self::assertStringContainsString('SHOW CREATE EVENT `nightly`;', (string) $findings[0]->suggestion);
        self::assertStringContainsString('ALTER DEFINER = CURRENT_USER EVENT `nightly` ENABLE;', (string) $findings[0]->suggestion);
        // Un déclencheur se range sous sa table.
        self::assertSame('orders', $findings[3]->table);
        self::assertStringContainsString('déclencheur', (string) $findings[3]->detail);
        self::assertStringContainsString('SHOW CREATE TRIGGER `trg_audit`;', (string) $findings[3]->suggestion);
        self::assertStringContainsString('DROP TRIGGER', (string) $findings[3]->suggestion);
        self::assertStringContainsString('SHOW CREATE VIEW `v_totals`;', (string) $findings[4]->suggestion);
        self::assertStringContainsString('SHOW CREATE PROCEDURE `refresh`;', (string) $findings[1]->suggestion);
        self::assertStringContainsString('SHOW CREATE FUNCTION `total`;', (string) $findings[2]->suggestion);
        foreach ($findings as $finding) {
            foreach (explode("\n", (string) $finding->suggestion) as $line) {
                self::assertStringStartsWith('--', $line, 'nothing executable, only comments to review');
            }
        }
    }

    public function testDefinersAreNotJudgedOnPostgreSql(): void
    {
        self::assertSame([], (new SchemaAuditor())->operations(Engine::PostgreSql, null, DefinerReport::of([new DefinerObject(DefinerKind::View, 'v', null, 'x@%')])));
        self::assertSame([], (new SchemaAuditor())->operationsChecks(Engine::PostgreSql, null, DefinerReport::unavailable('not_supported')));
    }

    public function testUnreadableDefinersAreReportedAsUnchecked(): void
    {
        $checks = (new SchemaAuditor())->operationsChecks(Engine::MySql, null, DefinerReport::unavailable('permission_denied'));

        self::assertCount(1, $checks);
        self::assertSame(FindingRule::OrphanDefiner, $checks[0]->rule);
        self::assertSame('permission_denied', $checks[0]->reason);
    }
}
