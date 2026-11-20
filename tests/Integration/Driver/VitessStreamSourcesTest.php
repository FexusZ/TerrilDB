<?php

declare(strict_types=1);

namespace App\Tests\Integration\Driver;

use App\Core\Connection\Engine;
use App\Core\Driver\Exception\TargetConnectionFailedException;
use App\Core\Driver\TargetConnection;
use App\Core\Query\QueryService;
use App\Driver\DbalConnector;
use App\Driver\MySql\MySqlDriver;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\SkippedWithMessageException;
use PHPUnit\Framework\TestCase;

/**
 * Un flux VReplication (Materialize, MoveTables) ne suit pas un ALTER de sa table source (#40). La cible de test n'a pas de vrai flux : on
 * écrit dans `_vt.vreplication` du primaire une ligne `Stopped` (le tablet ne la joue pas) au format réel du champ `source`, puis on la retire.
 */
#[Group('integration')]
final class VitessStreamSourcesTest extends TestCase
{
    private const WORKFLOW = 'tdb_materialize_test';

    private ?TargetConnection $gateway = null;
    private ?Connection $primary = null;

    protected function tearDown(): void
    {
        try {
            $this->primary?->executeStatement('DELETE FROM _vt.vreplication WHERE workflow = ?', [self::WORKFLOW]);
        } finally {
            $this->primary?->close();
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
        foreach ($this->gateway->diagnostics()->tablets()->tablets() as $tablet) {
            if ('PRIMARY' === $tablet->type) {
                $this->primary = (new DbalConnector())->connect(Engine::MySql, $parameters->withDatabase($tablet->target()));
            }
        }
        self::assertNotNull($this->primary, 'target-vitess has a primary tablet');

        return $this->gateway;
    }

    private function createStream(string $source, string $state = 'Stopped'): void
    {
        $this->primary?->executeStatement(
            'INSERT INTO _vt.vreplication (workflow, source, pos, max_tps, max_replication_lag, time_updated, transaction_timestamp, state, db_name, options) VALUES (?, ?, \'\', 0, 0, 0, 0, ?, \'vt_shop\', \'{}\')',
            [self::WORKFLOW, $source, $state],
        );
    }

    public function testAMaterializeSourceIsFoundWhereverTheStreamIsStored(): void
    {
        $gateway = $this->open();
        $this->createStream('keyspace:"shop" shard:"0" filter:{rules:{match:"tdb_sales" filter:"select sku from tdb_orders group by sku"}}');

        self::assertTrue(QueryService::feedsAStream($gateway, 'ALTER TABLE tdb_orders ADD COLUMN note text'));
        self::assertFalse(QueryService::feedsAStream($gateway, 'ALTER TABLE tdb_sales ADD COLUMN note text'));
        self::assertFalse(QueryService::feedsAStream($gateway, 'ALTER TABLE customers ADD COLUMN note text'));
    }

    public function testNoStreamMeansNoWarning(): void
    {
        $gateway = $this->open();

        self::assertFalse(QueryService::feedsAStream($gateway, 'ALTER TABLE tdb_orders ADD COLUMN note text'));
    }

    public function testAStreamFromAnotherKeyspaceIsNotThisKeyspacesSource(): void
    {
        $gateway = $this->open();
        $this->createStream('keyspace:"elsewhere" shard:"0" filter:{rules:{match:"tdb_sales" filter:"select sku from tdb_orders"}}');

        self::assertFalse(QueryService::feedsAStream($gateway, 'ALTER TABLE tdb_orders ADD COLUMN note text'));
    }

    public function testPlainMySqlHasNoStreams(): void
    {
        $target = (new MySqlDriver(new DbalConnector()))->connect(Engine::MySql, TargetDatabase::parameters('TARGET_MYSQL_URL'));
        try {
            self::assertNull($target->streams());
        } finally {
            $target->close();
        }
    }
}
