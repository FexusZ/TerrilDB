<?php

declare(strict_types=1);

namespace App\Tests\Core\Optimizer;

use App\Core\Connection\Engine;
use App\Core\Optimizer\FindingRule;
use App\Core\Optimizer\SchemaAuditor;
use App\Core\Optimizer\Severity;
use App\Core\Schema\AutoCounter;
use App\Core\Schema\ColumnDefinition;
use App\Core\Schema\TableDefinition;
use App\Core\Schema\TableKind;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CounterSaturationTest extends TestCase
{
    /**
     * @return list<\App\Core\Optimizer\Finding>
     */
    private function audit(Engine $engine, string $type, string $last, ?AutoCounter $counter = null): array
    {
        $table = new TableDefinition('t', TableKind::Table, [new ColumnDefinition('id', $type, false, null, true, null)], [], [], null, null);

        return $this->counterFindings((new SchemaAuditor())->audit($engine, [$table], [$counter ?? new AutoCounter('t', 'id', $last)]));
    }

    /**
     * @param list<\App\Core\Optimizer\Finding> $findings
     *
     * @return list<\App\Core\Optimizer\Finding>
     */
    private function counterFindings(array $findings): array
    {
        return array_values(array_filter($findings, static fn ($f): bool => FindingRule::CounterNearLimit === $f->rule));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function limits(): iterable
    {
        yield 'tinyint' => ['tinyint', '127'];
        yield 'tinyint unsigned' => ['tinyint unsigned', '255'];
        yield 'smallint' => ['smallint', '32767'];
        yield 'smallint(5) unsigned' => ['smallint(5) unsigned', '65535'];
        yield 'mediumint' => ['mediumint', '8388607'];
        yield 'mediumint unsigned' => ['mediumint unsigned', '16777215'];
        yield 'int' => ['int(11)', '2147483647'];
        yield 'int unsigned' => ['int unsigned', '4294967295'];
        yield 'bigint' => ['bigint', '9223372036854775807'];
        yield 'bigint unsigned' => ['bigint(20) unsigned', '18446744073709551615'];
    }

    #[DataProvider('limits')]
    public function testEveryIntegerWidthIsGraveAtItsOwnLimit(string $type, string $max): void
    {
        $findings = $this->audit(Engine::MySql, $type, $max);

        self::assertCount(1, $findings);
        self::assertSame(FindingRule::CounterNearLimit, $findings[0]->rule);
        self::assertSame(Severity::Critical, $findings[0]->severity);
        self::assertSame(['id'], $findings[0]->columns);
    }

    public function testThresholdsAre70And90Percent(): void
    {
        self::assertSame([], $this->audit(Engine::MySql, 'smallint unsigned', '45874'));
        self::assertSame(Severity::Warning, $this->audit(Engine::MySql, 'smallint unsigned', '45875')[0]->severity);
        self::assertSame(Severity::Warning, $this->audit(Engine::MySql, 'smallint unsigned', '58981')[0]->severity);
        self::assertSame(Severity::Critical, $this->audit(Engine::MySql, 'smallint unsigned', '58982')[0]->severity);
    }

    public function testASmallShareOfABigintSaysNothing(): void
    {
        self::assertSame([], $this->audit(Engine::MySql, 'bigint', '92233720368547758'));
        self::assertSame([], $this->audit(Engine::MySql, 'int', '644245094'));
    }

    public function testMySqlSuggestsABiggerTypeKeepingTheSign(): void
    {
        $unsigned = $this->audit(Engine::MySql, 'int unsigned', '4000000000')[0];
        self::assertSame('ALTER TABLE `t` MODIFY `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT;', $unsigned->suggestion);
        self::assertSame('int unsigned : 4000000000 sur 4294967295 (93 %)', $unsigned->detail);

        $signed = $this->audit(Engine::MySql, 'int(11)', '2100000000')[0];
        self::assertSame('ALTER TABLE `t` MODIFY `id` BIGINT NOT NULL AUTO_INCREMENT;', $signed->suggestion);
    }

    public function testASignedBigintCanOnlyGainItsNegativeHalf(): void
    {
        $finding = $this->audit(Engine::MySql, 'bigint', '8999999999999999999')[0];
        self::assertSame('ALTER TABLE `t` MODIFY `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT;', $finding->suggestion);

        $unsigned = $this->audit(Engine::MySql, 'bigint unsigned', '18000000000000000000')[0];
        self::assertNull($unsigned->suggestion);
    }

    public function testPostgreSqlIdentityColumnConvertsItsSequenceWithTheType(): void
    {
        $finding = $this->audit(Engine::PostgreSql, 'integer', '2100000000', new AutoCounter('t', 'id', '2100000000', '2147483647', 'public.t_id_seq', true))[0];

        self::assertSame('ALTER TABLE "t" ALTER COLUMN "id" TYPE bigint;', $finding->suggestion);
    }

    public function testPostgreSqlSerialNeedsItsSequenceWidenedToo(): void
    {
        $finding = $this->audit(Engine::PostgreSql, 'integer', '2100000000', new AutoCounter('t', 'id', '2100000000', '2147483647', 'public.t_id_seq', false))[0];

        self::assertSame(
            "ALTER TABLE \"t\" ALTER COLUMN \"id\" TYPE bigint;\nALTER SEQUENCE \"public\".\"t_id_seq\" AS bigint;",
            $finding->suggestion,
        );
    }

    public function testASequenceCappedBelowItsColumnTypeIsJudgedOnTheSequence(): void
    {
        // Colonne bigint, mais séquence plafonnée à 1000 : la jauge est celle de la séquence.
        $findings = $this->audit(Engine::PostgreSql, 'bigint', '950', new AutoCounter('t', 'id', '950', '1000', 'public.t_id_seq', false));

        self::assertSame(Severity::Critical, $findings[0]->severity);
        self::assertSame('ALTER SEQUENCE "public"."t_id_seq" MAXVALUE 9223372036854775807;', $findings[0]->suggestion);
    }

    public function testAnUnknownTypeOrAMissingCounterIsNotJudged(): void
    {
        self::assertSame([], $this->audit(Engine::MySql, 'decimal(10,0)', '99999999'));
        $table = new TableDefinition('t', TableKind::Table, [new ColumnDefinition('id', 'int', false, null, true, null)], [], [], null, null);
        self::assertSame([], $this->counterFindings((new SchemaAuditor())->audit(Engine::MySql, [$table], [])));
    }

    public function testGraveFindingsComeBeforeWarnings(): void
    {
        $tables = [new TableDefinition('a', TableKind::Table, [
            new ColumnDefinition('id', 'tinyint unsigned', false, null, true, null),
            new ColumnDefinition('n', 'tinyint unsigned', false, null, false, null),
        ], [], [], null, null)];
        $findings = (new SchemaAuditor())->audit(Engine::MySql, $tables, [new AutoCounter('a', 'n', '200'), new AutoCounter('a', 'id', '250')]);

        $rules = array_values(array_filter($findings, static fn ($f): bool => FindingRule::CounterNearLimit === $f->rule));
        self::assertSame(['id', 'n'], array_map(static fn ($f): string => $f->columns[0], $rules));
        self::assertSame([Severity::Critical, Severity::Warning], array_map(static fn ($f): Severity => $f->severity, $rules));
    }
}
