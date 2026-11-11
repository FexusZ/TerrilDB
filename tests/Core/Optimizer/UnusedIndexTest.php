<?php

declare(strict_types=1);

namespace App\Tests\Core\Optimizer;

use App\Core\Connection\Engine;
use App\Core\Optimizer\CheckState;
use App\Core\Optimizer\Finding;
use App\Core\Optimizer\FindingRule;
use App\Core\Optimizer\SchemaAuditor;
use App\Core\Optimizer\Severity;
use App\Core\Schema\ColumnDefinition;
use App\Core\Schema\ForeignKeyDefinition;
use App\Core\Schema\IndexDefinition;
use App\Core\Schema\IndexUsage;
use App\Core\Schema\IndexUsageReport;
use App\Core\Schema\ReferentialAction;
use App\Core\Schema\TableDefinition;
use App\Core\Schema\TableKind;
use PHPUnit\Framework\TestCase;

final class UnusedIndexTest extends TestCase
{
    private const WEEK = 604800;

    private function table(): TableDefinition
    {
        $columns = array_map(static fn (string $n): ColumnDefinition => new ColumnDefinition($n, 'integer', false, null, false, null), ['id', 'a', 'b', 'owner_id']);

        return new TableDefinition('t', TableKind::Table, $columns, [
            new IndexDefinition('PRIMARY', ['id'], true, true),
            new IndexDefinition('idx_a', ['a'], false, false),
            new IndexDefinition('uniq_b', ['b'], true, false),
            new IndexDefinition('idx_owner', ['owner_id', 'a'], false, false),
            new IndexDefinition('idx_busy', ['b', 'a'], false, false),
        ], [new ForeignKeyDefinition('fk_owner', ['owner_id'], 'o', ['id'], ReferentialAction::NoAction, ReferentialAction::NoAction)], null, null);
    }

    private function usage(int $seconds = self::WEEK): IndexUsageReport
    {
        return IndexUsageReport::available($seconds, [
            new IndexUsage('t', 'PRIMARY', 0),
            new IndexUsage('t', 'idx_a', 0),
            new IndexUsage('t', 'uniq_b', 0),
            new IndexUsage('t', 'idx_owner', 0),
            new IndexUsage('t', 'idx_busy', 12),
        ]);
    }

    /**
     * @return list<Finding>
     */
    private function unused(Engine $engine, IndexUsageReport $usage, ?TableDefinition $table = null): array
    {
        $findings = (new SchemaAuditor())->audit($engine, [$table ?? $this->table()], usage: $usage);

        return array_values(array_filter($findings, static fn (Finding $f): bool => FindingRule::UnusedIndex === $f->rule));
    }

    public function testOnlyAnIndexNeverReadAndFreeOfConstraintsIsReported(): void
    {
        $findings = $this->unused(Engine::MySql, $this->usage());

        // Ni la clé primaire, ni l'unique, ni celui qui couvre la clé étrangère, ni l'index lu.
        self::assertCount(1, $findings);
        self::assertSame('idx_a', $findings[0]->index);
        self::assertSame(['a'], $findings[0]->columns);
        self::assertSame(Severity::Info, $findings[0]->severity);
    }

    public function testObservedDurationIsShownInTheDetail(): void
    {
        self::assertSame('aucune lecture en 12 jours', $this->unused(Engine::MySql, $this->usage(12 * 86400 + 3600))[0]->detail);
        self::assertSame('aucune lecture en 9 jours', $this->unused(Engine::MySql, IndexUsageReport::available(9 * 86400, [new IndexUsage('t', 'idx_a', 0)]))[0]->detail);
    }

    public function testNothingIsReportedBeforeAWeekOfObservation(): void
    {
        self::assertSame([], $this->unused(Engine::MySql, $this->usage(self::WEEK - 1)));
    }

    public function testShortObservationIsSaidNotSilentlyIgnored(): void
    {
        $checks = (new SchemaAuditor())->checks(Engine::MySql, $this->usage(3600));

        self::assertCount(1, $checks);
        self::assertSame(FindingRule::UnusedIndex, $checks[0]->rule);
        self::assertSame(CheckState::TooShort, $checks[0]->state);
        self::assertSame('3600', $checks[0]->detail);
        self::assertSame([], (new SchemaAuditor())->checks(Engine::MySql, $this->usage()));
    }

    public function testUnreadableSourceIsAnUnavailableStateNotAFinding(): void
    {
        $usage = IndexUsageReport::unavailable('performance_schema_off');

        self::assertSame([], $this->unused(Engine::MySql, $usage));
        $checks = (new SchemaAuditor())->checks(Engine::MySql, $usage);
        self::assertCount(1, $checks);
        self::assertSame(CheckState::Unavailable, $checks[0]->state);
        self::assertSame('performance_schema_off', $checks[0]->reason);
    }

    public function testAnEngineWithoutIndexCountersHasNoCheckAtAll(): void
    {
        self::assertSame([], (new SchemaAuditor())->checks(Engine::ClickHouse, IndexUsageReport::unavailable('not_supported')));
        self::assertSame([], (new SchemaAuditor())->checks(Engine::MySql, null));
    }

    public function testSuggestionIsMadeInvisibleFirstOnMySqlAndIgnoredOnMariaDb(): void
    {
        $mysql = $this->unused(Engine::MySql, $this->usage())[0]->suggestion;
        self::assertSame("ALTER TABLE `t` ALTER INDEX `idx_a` INVISIBLE;\n-- après une période représentative, si rien n'a ralenti :\n-- ALTER TABLE `t` DROP INDEX `idx_a`;", $mysql);

        $maria = $this->unused(Engine::MariaDb, $this->usage())[0]->suggestion;
        self::assertSame("ALTER TABLE `t` ALTER INDEX `idx_a` IGNORED;\n-- après une période représentative, si rien n'a ralenti :\n-- ALTER TABLE `t` DROP INDEX `idx_a`;", $maria);
    }

    public function testPostgreSqlHasNoInvisibleIndexSoTheDropIsCommentedOut(): void
    {
        $suggestion = $this->unused(Engine::PostgreSql, $this->usage())[0]->suggestion;

        self::assertSame("-- PostgreSQL n'a pas d'index invisible : à supprimer seulement après une période représentative.\n-- DROP INDEX \"idx_a\";", $suggestion);
    }

    public function testAnUnknownIndexOrAViewIsNotJudged(): void
    {
        $usage = IndexUsageReport::available(self::WEEK, [new IndexUsage('t', 'ghost', 0), new IndexUsage('other', 'idx_a', 0)]);

        self::assertSame([], $this->unused(Engine::MySql, $usage));
    }
}
