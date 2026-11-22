<?php

declare(strict_types=1);

namespace App\Tests\Integration\Web;

use App\Core\Connection\Engine;
use App\Core\Driver\Exception\TargetConnectionFailedException;
use App\Driver\DbalConnector;
use App\Tests\Integration\Driver\TargetDatabase;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\Group;

/**
 * Console et scripts d'un vtgate : modifier la structure d'une table qui nourrit un flux (Materialize, MoveTables) demande une confirmation
 * « attention » (le flux ne suivra pas), jamais un refus. La ligne `_vt.vreplication` est écrite `Stopped` puis retirée. Sautée si la cible est éteinte.
 */
#[Group('integration')]
final class VitessStreamWarningWebTest extends WebApiTestCase
{
    private const WORKFLOW = 'tdb_stream_warning_test';

    private ?Connection $primary = null;

    protected function tearDown(): void
    {
        try {
            $this->primary?->executeStatement('DELETE FROM _vt.vreplication WHERE workflow = ?', [self::WORKFLOW]);
        } finally {
            $this->primary?->close();
            parent::tearDown();
        }
    }

    private function gatewayWithAStream(): string
    {
        $parameters = TargetDatabase::parameters('TARGET_VITESS_URL');
        $created = $this->call('POST', '/web/connections', ['name' => 'Gateway', 'engine' => 'mysql', 'host' => $parameters->host, 'port' => $parameters->port, 'database' => $parameters->database, 'username' => $parameters->username, 'password' => $parameters->password()]);
        $id = self::arrayOf($created['connection'])['id'];
        self::assertIsString($id);
        $tablets = $this->call('GET', '/web/connections/'.$id.'/diagnostics/tablets');
        if (502 === $this->statusCode()) {
            self::markTestSkipped('TARGET_VITESS_URL is unreachable (docker compose --profile vitess up -d --wait target-vitess).');
        }
        foreach (self::listOf($tablets['tablets']) as $tablet) {
            $tablet = self::arrayOf($tablet);
            if ('PRIMARY' === $tablet['type']) {
                self::assertIsString($tablet['keyspace']);
                self::assertIsString($tablet['shard']);
                self::assertIsString($tablet['alias']);
                $target = \sprintf('%s:%s@primary|%s', $tablet['keyspace'], $tablet['shard'], $tablet['alias']);
                try {
                    $this->primary = (new DbalConnector())->connect(Engine::MySql, $parameters->withDatabase($target));
                } catch (TargetConnectionFailedException) {
                    self::markTestSkipped('The primary tablet cannot be reached.');
                }
            }
        }
        self::assertNotNull($this->primary);
        $this->primary->executeStatement(
            'INSERT INTO _vt.vreplication (workflow, source, pos, max_tps, max_replication_lag, time_updated, transaction_timestamp, state, db_name, options) VALUES (?, ?, \'\', 0, 0, 0, 0, \'Stopped\', \'vt_shop\', \'{}\')',
            [self::WORKFLOW, 'keyspace:"shop" shard:"0" filter:{rules:{match:"tdb_sales" filter:"select sku from tdb_orders"}}'],
        );

        return $id;
    }

    public function testTheConsoleWarnsAboutAStreamSourceInsteadOfRefusing(): void
    {
        $this->seedAndLogin();
        $id = $this->gatewayWithAStream();

        $body = $this->call('POST', '/web/connections/'.$id.'/query', ['sql' => 'ALTER TABLE tdb_orders ADD COLUMN note text', 'mode' => 'read_write']);

        self::assertSame(409, $this->statusCode());
        self::assertSame('confirmation_required', $body['error']);
        self::assertSame('stream_source', $body['reason']);
        self::assertSame([['workflow' => self::WORKFLOW, 'table' => 'tdb_orders', 'targets' => ['tdb_sales']]], $body['streams']);
    }

    public function testAnotherTableKeepsTheUsualWarning(): void
    {
        $this->seedAndLogin();
        $id = $this->gatewayWithAStream();

        $body = $this->call('POST', '/web/connections/'.$id.'/query', ['sql' => 'ALTER TABLE tdb_other ADD COLUMN note text', 'mode' => 'read_write']);

        self::assertSame(409, $this->statusCode());
        self::assertSame('destructive_statement', $body['reason']);
    }

    public function testTheScriptConsoleWarnsToo(): void
    {
        $this->seedAndLogin();
        $id = $this->gatewayWithAStream();

        $body = $this->call('POST', '/web/connections/'.$id.'/script/execute', ['script' => 'ALTER TABLE tdb_orders ADD COLUMN note text;']);

        self::assertSame(409, $this->statusCode());
        self::assertSame('confirmation_required', $body['error']);
        self::assertSame('stream_source', $body['reason']);
    }
}
