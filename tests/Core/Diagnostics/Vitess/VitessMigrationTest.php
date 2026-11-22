<?php

declare(strict_types=1);

namespace App\Tests\Core\Diagnostics\Vitess;

use App\Core\Diagnostics\Vitess\MigrationAction;
use App\Core\Diagnostics\Vitess\VitessMigration;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Une ligne de `SHOW VITESS_MIGRATIONS` (relevée sur target-vitess). Les valeurs viennent du serveur : seul un UUID de la forme
 * attendue peut devenir l'argument d'un `ALTER VITESS_MIGRATION`, jamais un texte libre.
 */
final class VitessMigrationTest extends TestCase
{
    private const UUID = 'ffc701c7_e6a9_11f1_baef_9a5268ba1852';

    /**
     * @param array<string, mixed> $override
     *
     * @return array<string, mixed>
     */
    private function row(array $override = []): array
    {
        return $override + [
            'migration_uuid' => self::UUID, 'keyspace' => 'shop', 'shard' => '0', 'mysql_table' => 'orders',
            'migration_statement' => "alter table orders add column note varchar(20) default 'secret'", 'strategy' => 'vitess', 'options' => '--postpone-completion',
            'added_timestamp' => '2026-11-22 19:22:04', 'started_timestamp' => null, 'completed_timestamp' => null, 'migration_status' => 'queued',
            'progress' => '0', 'message' => '', 'retries' => '0', 'eta_seconds' => '-1', 'rows_copied' => '0', 'tablet' => 'test-0000000001',
            'postpone_completion' => '1', 'postpone_launch' => '0', 'ready_to_complete' => '0',
        ];
    }

    public function testARealRowBecomesAMigrationWithItsStatementMasked(): void
    {
        $migration = VitessMigration::fromRow($this->row());

        self::assertNotNull($migration);
        self::assertSame(self::UUID, $migration->uuid);
        self::assertSame('orders', $migration->table);
        self::assertSame('queued', $migration->status);
        self::assertSame('vitess', $migration->strategy);
        self::assertSame('alter table orders add column note varchar(?) default ?', $migration->statement, 'les littéraux sont masqués');
        self::assertTrue($migration->postponeCompletion);
    }

    public function testARowWithoutAnUsableUuidIsDropped(): void
    {
        self::assertNull(VitessMigration::fromRow($this->row(['migration_uuid' => "x'; DROP TABLE t; --"])));
        self::assertNull(VitessMigration::fromRow($this->row(['migration_uuid' => null])));
    }

    public function testAnUnknownStatusIsNeverRenderedAsIs(): void
    {
        self::assertSame('unknown', VitessMigration::fromRow($this->row(['migration_status' => '<script>']))?->status);
    }

    public function testTheProgressIsBounded(): void
    {
        self::assertSame(100, VitessMigration::fromRow($this->row(['progress' => '250']))?->progress);
        self::assertSame(0, VitessMigration::fromRow($this->row(['progress' => 'abc']))?->progress);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, list<string>}>
     */
    public static function actions(): iterable
    {
        yield 'queued' => [['migration_status' => 'queued'], ['cancel']];
        yield 'queued, launch postponed' => [['migration_status' => 'queued', 'postpone_launch' => '1'], ['launch', 'cancel']];
        yield 'ready' => [['migration_status' => 'ready'], ['cancel']];
        yield 'running, completion postponed' => [['migration_status' => 'running', 'postpone_completion' => '1'], ['complete', 'cancel']];
        yield 'running, automatic completion' => [['migration_status' => 'running', 'postpone_completion' => '0'], ['cancel']];
        yield 'failed' => [['migration_status' => 'failed'], ['retry']];
        yield 'cancelled' => [['migration_status' => 'cancelled'], ['retry']];
        yield 'complete' => [['migration_status' => 'complete'], []];
        yield 'unknown' => [['migration_status' => 'weird'], []];
    }

    /**
     * @param array<string, mixed> $override
     * @param list<string>         $expected
     */
    #[DataProvider('actions')]
    public function testTheActionsOfferedFollowTheStatus(array $override, array $expected): void
    {
        $migration = VitessMigration::fromRow($this->row($override));

        self::assertNotNull($migration);
        self::assertSame($expected, array_map(static fn (MigrationAction $a): string => $a->value, $migration->actions()));
    }

    public function testEachActionIsOneStatementOnTheCheckedUuid(): void
    {
        self::assertSame("ALTER VITESS_MIGRATION '".self::UUID."' CANCEL", MigrationAction::Cancel->statement(self::UUID));
        self::assertSame("ALTER VITESS_MIGRATION '".self::UUID."' RETRY", MigrationAction::Retry->statement(self::UUID));
        self::assertSame("ALTER VITESS_MIGRATION '".self::UUID."' COMPLETE", MigrationAction::Complete->statement(self::UUID));
        self::assertSame("ALTER VITESS_MIGRATION '".self::UUID."' LAUNCH", MigrationAction::Launch->statement(self::UUID));
    }

    public function testAStatementIsRefusedForAnUnsafeUuid(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        MigrationAction::Cancel->statement("x' OR '1");
    }
}
