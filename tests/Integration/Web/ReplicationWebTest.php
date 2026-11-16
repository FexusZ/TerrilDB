<?php

declare(strict_types=1);

namespace App\Tests\Integration\Web;

use App\Audit\AuditAction;
use App\Tests\Integration\Driver\TargetDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * La route « Réplication » de l'interface. Les cibles de test n'ont pas de réplica : on y lit « pas de réplication configurée ».
 */
#[Group('integration')]
final class ReplicationWebTest extends WebApiTestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function relational(): iterable
    {
        yield from TargetDatabase::relational();
    }

    private function saveTarget(string $envVar): string
    {
        $parameters = TargetDatabase::parameters($envVar);
        $created = $this->call('POST', '/web/connections', ['name' => 'Target', 'engine' => TargetDatabase::engine($envVar)->value, 'host' => $parameters->host, 'port' => $parameters->port, 'database' => $parameters->database, 'username' => $parameters->username, 'password' => $parameters->password()]);
        $id = self::arrayOf($created['connection'])['id'];
        self::assertIsString($id);

        return $id;
    }

    public function testRequiresASession(): void
    {
        $this->call('GET', '/web/connections/00000000-0000-7000-8000-000000000000/diagnostics/replication');

        self::assertSame(401, $this->statusCode());
    }

    #[DataProvider('relational')]
    public function testAServerWithoutReplicationSaysSo(string $envVar): void
    {
        $this->seedAndLogin();
        $id = $this->saveTarget($envVar);

        $body = $this->call('GET', '/web/connections/'.$id.'/diagnostics/replication');

        self::assertSame(200, $this->statusCode());
        self::assertSame(['status', 'role', 'replicaCount', 'settings', 'channels', 'alerts', 'thresholds'], array_keys($body));
        self::assertSame('available', self::arrayOf($body['status'])['availability']);
        self::assertSame('none', $body['role']);
        self::assertSame([], $body['channels']);
        self::assertSame([], $body['alerts']);
    }

    public function testCapabilitiesListReplication(): void
    {
        $this->seedAndLogin();
        $id = $this->saveTarget('TARGET_POSTGRES_URL');

        $body = $this->call('GET', '/web/connections/'.$id.'/diagnostics');

        self::assertSame('available', self::arrayOf(self::arrayOf($body['capabilities'])['replication'])['availability']);
    }

    public function testClickHouseIsUnsupportedHere(): void
    {
        $this->seedAndLogin();
        $id = $this->saveTarget('TARGET_CLICKHOUSE_URL');

        $body = $this->call('GET', '/web/connections/'.$id.'/diagnostics/replication');

        self::assertSame(501, $this->statusCode());
        self::assertSame('unsupported_operation', $body['error']);
    }

    public function testAnotherAccountsConnectionIsNotFound(): void
    {
        $this->seedUser('alice');
        $this->seedUser('bob');
        $this->login('alice');
        $id = $this->saveTarget('TARGET_POSTGRES_URL');
        $this->call('POST', '/web/logout');
        $this->login('bob');

        $this->call('GET', '/web/connections/'.$id.'/diagnostics/replication');

        self::assertSame(404, $this->statusCode());
    }

    public function testTheReadIsAuditedWithoutAnyErrorText(): void
    {
        $user = $this->seedAndLogin();
        $id = $this->saveTarget('TARGET_POSTGRES_URL');

        $this->call('GET', '/web/connections/'.$id.'/diagnostics/replication');

        $events = array_values(array_filter($this->auditEvents($user, 'diagnostic'), static fn ($e): bool => AuditAction::DiagnosticRead === $e->getAction()));
        self::assertCount(1, $events);
        self::assertSame(['topic' => 'replication', 'engine' => 'postgresql', 'status' => 'available', 'role' => 'none', 'channels' => 0, 'alerts' => 0], $events[0]->getDetails());
    }
}
