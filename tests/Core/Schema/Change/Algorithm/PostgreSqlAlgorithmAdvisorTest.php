<?php

declare(strict_types=1);

namespace App\Tests\Core\Schema\Change\Algorithm;

use App\Core\Connection\Engine;
use App\Core\Driver\ServerVersion;
use App\Core\Schema\Change\Algorithm\AlgorithmAdvice;
use App\Core\Schema\Change\Algorithm\AlterAlgorithm;
use App\Core\Schema\Change\Algorithm\AlterAlgorithmAdvisor;
use App\Core\Schema\Change\ColumnDraft;
use App\Core\Schema\Change\IndexDraft;
use App\Core\Schema\Change\SchemaChangePlanner;
use App\Core\Schema\Change\TableDraft;
use App\Core\Schema\ColumnDefinition;
use App\Core\Schema\IndexDefinition;
use App\Core\Schema\TableDefinition;
use App\Core\Schema\TableKind;
use PHPUnit\Framework\TestCase;

/**
 * PostgreSQL : seulement « réécriture » contre « sans réécriture », pour les cas vérifiés (tests/Integration/Driver/AlterAlgorithmTest).
 */
final class PostgreSqlAlgorithmAdvisorTest extends TestCase
{
    private static function table(): TableDefinition
    {
        return new TableDefinition(
            'orders',
            TableKind::Table,
            [
                new ColumnDefinition('id', 'integer', false, null, false, null),
                new ColumnDefinition('name', 'character varying(40)', false, null, false, null),
                new ColumnDefinition('qty', 'integer', true, null, false, null),
                new ColumnDefinition('note', 'text', true, null, false, null),
            ],
            [new IndexDefinition('orders_pkey', ['id'], true, true)],
            [],
            null,
            500_000,
            8_000_000,
            2_000_000,
        );
    }

    /**
     * @param array<string, ColumnDraft> $replace columns changed, by current name
     * @param list<ColumnDraft>          $add
     * @param list<string>               $drop
     * @param list<IndexDraft>|null      $indexes
     *
     * @return list<AlgorithmAdvice>
     */
    private static function advice(array $replace = [], array $add = [], array $drop = [], string $version = '18.1', ?array $indexes = null): array
    {
        $current = self::table();
        $columns = [];
        foreach ($current->columns as $c) {
            if (\in_array($c->name, $drop, true)) {
                continue;
            }
            $columns[] = $replace[$c->name] ?? new ColumnDraft($c->name, $c->name, $c->type, $c->nullable, $c->default, $c->autoIncrement);
        }
        $draft = new TableDraft('orders', [...$columns, ...$add], $indexes ?? [new IndexDraft('orders_pkey', 'orders_pkey', ['id'], true, true, null, null)], []);
        $plan = (new SchemaChangePlanner())->plan(Engine::PostgreSql, $current, $draft);

        return (new AlterAlgorithmAdvisor())->advise(Engine::PostgreSql, ServerVersion::parse($version), $current, $draft, $plan);
    }

    public function testAddingAColumnWithoutDefaultOrWithAConstantOneDoesNotRewrite(): void
    {
        foreach ([[true, null, 'integer'], [false, '0', 'integer'], [true, "'a'", 'text'], [true, 'now()', 'timestamp'], [true, 'true', 'boolean']] as [$nullable, $default, $type]) {
            $advice = self::advice(add: [new ColumnDraft(null, 'x', $type, $nullable, $default, false)])[0];

            self::assertSame(AlterAlgorithm::Instant, $advice->algorithm, $type.' '.($default ?? ''));
            self::assertFalse($advice->copiesTable);
            self::assertNull($advice->estimatedExtraBytes);
            self::assertNull($advice->blocksWrites, 'the lock is brief and not measured: nothing is claimed');
        }
    }

    public function testAVolatileDefaultRewritesTheTable(): void
    {
        $advice = self::advice(add: [new ColumnDraft(null, 'x', 'uuid', true, 'gen_random_uuid()', false)])[0];

        self::assertSame(AlterAlgorithm::Rewrite, $advice->algorithm);
        self::assertTrue($advice->copiesTable);
        self::assertTrue($advice->blocksWrites);
        self::assertSame(10_000_000, $advice->estimatedExtraBytes);
    }

    public function testSettingNotNullScansWithoutRewriting(): void
    {
        $advice = self::advice(replace: ['qty' => new ColumnDraft('qty', 'qty', 'integer', false, null, false)])[0];

        self::assertSame(AlterAlgorithm::Inplace, $advice->algorithm);
        self::assertTrue($advice->blocksWrites);
        self::assertFalse($advice->copiesTable);
    }

    public function testDroppingNotNullDefaultsRenamingAndDroppingColumnsDoNotRewrite(): void
    {
        $dropNotNull = self::advice(replace: ['name' => new ColumnDraft('name', 'name', 'character varying(40)', true, null, false)])[0];
        self::assertSame(AlterAlgorithm::Instant, $dropNotNull->algorithm);
        $default = self::advice(replace: ['qty' => new ColumnDraft('qty', 'qty', 'integer', true, '5', false)])[0];
        self::assertSame(AlterAlgorithm::Instant, $default->algorithm);
        $rename = self::advice(replace: ['qty' => new ColumnDraft('qty', 'quantity', 'integer', true, null, false)])[0];
        self::assertSame(AlterAlgorithm::Instant, $rename->algorithm);
        $drop = self::advice(drop: ['note'])[0];
        self::assertSame(AlterAlgorithm::Instant, $drop->algorithm);
    }

    public function testChangingTheTypeRewritesOnlyForTheVerifiedPatterns(): void
    {
        $retype = static fn (string $column, string $type): array => [$column => new ColumnDraft($column, $column, $type, 'name' !== $column, null, false)];

        foreach ([['qty', 'bigint'], ['qty', 'text'], ['qty', 'character varying(20)'], ['qty', 'smallint']] as [$column, $type]) {
            $advice = self::advice(replace: $retype($column, $type))[0];
            self::assertSame(AlterAlgorithm::Rewrite, $advice->algorithm, $type);
            self::assertTrue($advice->copiesTable);
        }
        foreach ([['name', 'character varying(80)'], ['name', 'text']] as [$column, $type]) {
            $advice = self::advice(replace: $retype($column, $type))[0];
            self::assertSame(AlterAlgorithm::Instant, $advice->algorithm, $type);
            self::assertFalse($advice->copiesTable);
        }
        self::assertSame(AlterAlgorithm::Unknown, self::advice(replace: $retype('name', 'character varying(10)'))[0]->algorithm, 'shrinking is not covered');
        self::assertSame(AlterAlgorithm::Unknown, self::advice(replace: $retype('note', 'jsonb'))[0]->algorithm);
    }

    public function testIndexesAreBuiltUnderAWriteBlockingLockWithoutRewriting(): void
    {
        $advice = self::advice(indexes: [new IndexDraft('orders_pkey', 'orders_pkey', ['id'], true, true, null, null), new IndexDraft(null, 'orders_name', ['name'], false, false, null, null)])[0];

        self::assertSame(AlterAlgorithm::Inplace, $advice->algorithm);
        self::assertTrue($advice->blocksWrites);
        self::assertFalse($advice->copiesTable);
    }

    public function testPrimaryKeyChangesAreNotClaimed(): void
    {
        $advice = self::advice(indexes: []);

        self::assertSame(AlterAlgorithm::Unknown, $advice[0]->algorithm);
    }

    public function testOnlyTheVerifiedMajorIsClaimed(): void
    {
        foreach (['17.5', '19.0'] as $version) {
            $advice = self::advice(add: [new ColumnDraft(null, 'x', 'integer', true, null, false)], version: $version)[0];
            self::assertSame(AlterAlgorithm::Unknown, $advice->algorithm, $version);
            self::assertSame('unverified_version', $advice->reason);
        }
    }
}
