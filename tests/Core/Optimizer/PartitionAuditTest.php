<?php

declare(strict_types=1);

namespace App\Tests\Core\Optimizer;

use App\Core\Connection\Engine;
use App\Core\Optimizer\FindingRule;
use App\Core\Optimizer\SchemaAuditor;
use App\Core\Optimizer\Severity;
use App\Core\Schema\ColumnDefinition;
use App\Core\Schema\PartitionDefinition;
use App\Core\Schema\PartitioningInfo;
use App\Core\Schema\PartitionMethod;
use App\Core\Schema\TableDefinition;
use App\Core\Schema\TableKind;
use PHPUnit\Framework\TestCase;

final class PartitionAuditTest extends TestCase
{
    private const NOW = '2026-11-13 02:00:00';

    /**
     * @param list<PartitionDefinition> $partitions
     */
    private function table(string $name, string $type, string $expression, array $partitions, PartitionMethod $method = PartitionMethod::Range): TableDefinition
    {
        return new TableDefinition(
            $name,
            TableKind::Table,
            [new ColumnDefinition('id', 'int', false, null, false, null), new ColumnDefinition('created_on', $type, false, null, false, null)],
            [],
            [],
            null,
            null,
            null,
            null,
            new PartitioningInfo($method, $expression, null, null, $partitions),
        );
    }

    private function range(string $name, ?string $upper): PartitionDefinition
    {
        return new PartitionDefinition($name, null === $upper ? 'LESS THAN (MAXVALUE)' : "LESS THAN ('".$upper."')", $upper, null === $upper, null, null);
    }

    /**
     * @return list<\App\Core\Optimizer\Finding>
     */
    private function audit(Engine $engine, TableDefinition $table): array
    {
        return array_values(array_filter(
            (new SchemaAuditor())->audit($engine, [$table], new \DateTimeImmutable(self::NOW)),
            static fn ($f): bool => \in_array($f->rule, [FindingRule::PartitionRangeExhausted, FindingRule::TooManyPartitions], true),
        ));
    }

    public function testARangeByDateWhoseLastBoundIsPastAndWithoutCatchAllIsFlagged(): void
    {
        $findings = $this->audit(Engine::MySql, $this->table('events', 'date', '`created_on`', [$this->range('p2023', '2024-01-01'), $this->range('p2024', '2025-01-01')]));

        self::assertCount(1, $findings);
        self::assertSame(FindingRule::PartitionRangeExhausted, $findings[0]->rule);
        self::assertSame(Severity::Warning, $findings[0]->severity);
        self::assertSame('events', $findings[0]->table);
        self::assertSame('2025-01-01', $findings[0]->detail);
        self::assertSame('ALTER TABLE `events` ADD PARTITION (PARTITION `pmax` VALUES LESS THAN (MAXVALUE));', $findings[0]->suggestion);
    }

    public function testPostgreSqlSuggestsADefaultPartition(): void
    {
        $findings = $this->audit(Engine::PostgreSql, $this->table('events', 'timestamp without time zone', 'created_on', [$this->range('events_p0', '2025-01-01 00:00:00')]));

        self::assertCount(1, $findings);
        self::assertSame('CREATE TABLE "events_default" PARTITION OF "events" DEFAULT;', $findings[0]->suggestion);
    }

    public function testACatchAllPartitionSilencesTheRule(): void
    {
        self::assertSame([], $this->audit(Engine::MySql, $this->table('events', 'date', '`created_on`', [$this->range('p2024', '2025-01-01'), $this->range('pmax', null)])));
    }

    public function testALastBoundInTheFutureSilencesTheRule(): void
    {
        self::assertSame([], $this->audit(Engine::MySql, $this->table('events', 'date', '`created_on`', [$this->range('p2024', '2025-01-01'), $this->range('p2027', '2027-01-01')])));
    }

    public function testWhatCannotBeVerifiedIsNotJudged(): void
    {
        // Expression plutôt que colonne nue, colonne entière, borne illisible, liste : on se tait.
        self::assertSame([], $this->audit(Engine::MySql, $this->table('events', 'date', 'to_days(`created_on`)', [$this->range('p', '2024-01-01')])));
        self::assertSame([], $this->audit(Engine::MySql, $this->table('events', 'int', '`created_on`', [$this->range('p', '2024-01-01')])));
        self::assertSame([], $this->audit(Engine::MySql, $this->table('events', 'date', '`created_on`', [$this->range('p', 'not a date')])));
        self::assertSame([], $this->audit(Engine::MySql, $this->table('events', 'date', '`created_on`', [new PartitionDefinition('p', "IN ('2020-01-01')", null, false, null, null)], PartitionMethod::List)));
    }

    public function testABoundOfTheLastDayIsGivenAMarginForTimeZones(): void
    {
        self::assertSame([], $this->audit(Engine::MySql, $this->table('events', 'date', '`created_on`', [$this->range('p', '2026-11-13')])));
    }

    public function testNoSuggestionWhenTheNameCannotBeWrittenSafely(): void
    {
        $findings = $this->audit(Engine::MySql, $this->table("ev\u{2028}ents", 'date', '`created_on`', [$this->range('p', '2024-01-01')]));

        self::assertCount(1, $findings);
        self::assertNull($findings[0]->suggestion);
    }

    public function testTooManyPartitionsAreFlaggedAboveTheDocumentedThreshold(): void
    {
        $many = [];
        for ($i = 0; $i <= SchemaAuditor::PARTITION_COUNT_ADVISED; ++$i) {
            $many[] = new PartitionDefinition('p'.$i, null, null, false, null, null);
        }
        $findings = $this->audit(Engine::MySql, $this->table('events', 'int', '`id`', $many, PartitionMethod::Hash));

        self::assertCount(1, $findings);
        self::assertSame(FindingRule::TooManyPartitions, $findings[0]->rule);
        self::assertSame(Severity::Info, $findings[0]->severity);
        self::assertSame((string) (SchemaAuditor::PARTITION_COUNT_ADVISED + 1), $findings[0]->detail);

        $enough = \array_slice($many, 0, SchemaAuditor::PARTITION_COUNT_ADVISED);
        self::assertSame([], $this->audit(Engine::MySql, $this->table('events', 'int', '`id`', $enough, PartitionMethod::Hash)));
    }
}
