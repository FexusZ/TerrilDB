<?php

declare(strict_types=1);

namespace App\Tests\Integration\Driver;

use App\Core\Connection\Engine;
use App\Core\Diagnostics\Vitess\MigrationAction;
use App\Core\Driver\Exception\TargetConnectionFailedException;
use App\Core\Driver\TargetConnection;
use App\Driver\DbalConnector;
use App\Driver\MySql\MySqlDriver;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\SkippedWithMessageException;
use PHPUnit\Framework\TestCase;

/**
 * Les migrations de DDL en ligne (#40) lues par `SHOW VITESS_MIGRATIONS` à travers vtgate, avec une VRAIE migration créée par la stratégie
 * de session `vitess --postpone-completion` sur une table jetable, puis annulée par l'instruction que `MigrationAction` écrit.
 */
#[Group('integration')]
final class VitessMigrationsTest extends TestCase
{
    private const TABLE = 'tdb_migration_probe';

    private ?TargetConnection $gateway = null;
    private ?Connection $session = null;

    protected function tearDown(): void
    {
        try {
            $this->session?->executeStatement('DROP TABLE IF EXISTS '.self::TABLE);
        } finally {
            $this->session?->close();
            $this->gateway?->close();
        }
    }

    private function open(): TargetConnection
    {
        $parameters = TargetDatabase::parameters('TARGET_VITESS_URL');
        try {
            $this->gateway = (new MySqlDriver(new DbalConnector()))->connect(Engine::MySql, $parameters);
        } catch (SkippedWithMessageException $e) {
            throw $e;
        } catch (TargetConnectionFailedException) {
            self::markTestSkipped('TARGET_VITESS_URL is unreachable (docker compose --profile vitess up -d --wait target-vitess).');
        }
        $this->session = (new DbalConnector())->connect(Engine::MySql, $parameters);
        $this->session->executeStatement('DROP TABLE IF EXISTS '.self::TABLE);
        $this->session->executeStatement('CREATE TABLE '.self::TABLE.' (id int primary key, a int)');

        return $this->gateway;
    }

    private function migrate(): string
    {
        $this->session?->executeStatement("SET @@ddl_strategy='vitess --postpone-completion'");
        $uuid = $this->session?->fetchOne('ALTER TABLE '.self::TABLE.' ADD COLUMN b int');
        self::assertIsString($uuid);

        return $uuid;
    }

    public function testARealMigrationIsListedWithItsStatusAndTheActionsThatFollow(): void
    {
        $gateway = $this->open();
        $uuid = $this->migrate();

        $report = $gateway->diagnostics()->migrations()->migrations();

        self::assertTrue($report->status->isAvailable());
        $found = null;
        foreach ($report->migrations as $migration) {
            if ($migration->uuid === $uuid) {
                $found = $migration;
            }
        }
        self::assertNotNull($found, 'the migration we just created is listed');
        self::assertSame(self::TABLE, $found->table);
        self::assertSame('shop', $found->keyspace);
        self::assertContains($found->status, ['queued', 'ready', 'running', 'failed', 'cancelled', 'complete']);
        self::assertStringContainsString('add column b int', (string) $found->statement);
    }

    public function testTheCancelStatementTheModelWritesIsAcceptedByTheServer(): void
    {
        $gateway = $this->open();
        $uuid = $this->migrate();

        $this->session?->executeStatement(MigrationAction::Cancel->statement($uuid));

        $statuses = [];
        foreach ($gateway->diagnostics()->migrations()->migrations()->migrations as $migration) {
            $statuses[$migration->uuid] = $migration->status;
        }
        self::assertContains($statuses[$uuid] ?? null, ['cancelled', 'failed'], 'a cancelled migration never keeps running');
    }

    public function testAPlainMySqlHasNoMigrations(): void
    {
        $target = (new MySqlDriver(new DbalConnector()))->connect(Engine::MySql, TargetDatabase::parameters('TARGET_MYSQL_URL'));
        try {
            $this->expectException(\App\Core\Driver\Exception\UnsupportedOperationException::class);
            $target->diagnostics()->migrations();
        } finally {
            $target->close();
        }
    }
}
