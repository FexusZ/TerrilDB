<?php

declare(strict_types=1);

namespace App\Tests\Http\Json;

use App\Core\Diagnostics\DiagnosticStatus;
use App\Core\Diagnostics\Vitess\MigrationReport;
use App\Core\Diagnostics\Vitess\VitessMigration;
use App\Http\Json\DiagnosticsPresenter;
use PHPUnit\Framework\TestCase;

final class MigrationsPresenterTest extends TestCase
{
    private function migration(string $status): VitessMigration
    {
        $migration = VitessMigration::fromRow([
            'migration_uuid' => 'ffc701c7_e6a9_11f1_baef_9a5268ba1852', 'keyspace' => 'shop', 'shard' => '0', 'mysql_table' => 'orders',
            'migration_statement' => 'alter table orders add column b int', 'strategy' => 'vitess', 'migration_status' => $status, 'progress' => '40',
            'postpone_completion' => '1',
        ]);
        self::assertNotNull($migration);

        return $migration;
    }

    public function testAMigrationCarriesTheReadyToSendStatementOfEachActionOffered(): void
    {
        $body = DiagnosticsPresenter::migrations(new MigrationReport(DiagnosticStatus::available(), [$this->migration('running')], true));

        self::assertSame(['status', 'truncated', 'migrations'], array_keys($body));
        self::assertTrue($body['truncated']);
        self::assertIsArray($body['migrations']);
        $first = $body['migrations'][0];
        self::assertIsArray($first);
        self::assertSame('ffc701c7_e6a9_11f1_baef_9a5268ba1852', $first['uuid']);
        self::assertSame(40, $first['progress']);
        self::assertSame([
            ['action' => 'complete', 'statement' => "ALTER VITESS_MIGRATION 'ffc701c7_e6a9_11f1_baef_9a5268ba1852' COMPLETE"],
            ['action' => 'cancel', 'statement' => "ALTER VITESS_MIGRATION 'ffc701c7_e6a9_11f1_baef_9a5268ba1852' CANCEL"],
        ], $first['actions']);
    }

    public function testAFinishedMigrationOffersNothing(): void
    {
        $body = DiagnosticsPresenter::migrations(new MigrationReport(DiagnosticStatus::available(), [$this->migration('complete')]));

        self::assertIsArray($body['migrations']);
        $first = $body['migrations'][0];
        self::assertIsArray($first);
        self::assertSame([], $first['actions']);
    }
}
