<?php

declare(strict_types=1);

namespace App\Tests\Integration\Web;

use App\Audit\AuditAction;
use App\Tests\Integration\Driver\RedisTarget;
use App\Tests\Integration\Driver\TargetDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

#[Group('integration')]
final class DiagnosticsWebTest extends WebApiTestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function targets(): iterable
    {
        yield from TargetDatabase::all();
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
        $this->call('GET', '/web/connections/00000000-0000-7000-8000-000000000000/diagnostics');
        self::assertSame(401, $this->statusCode());

        $this->call('GET', '/web/connections/00000000-0000-7000-8000-000000000000/diagnostics/sessions');
        self::assertSame(401, $this->statusCode());
    }

    #[DataProvider('targets')]
    public function testCapabilitiesSayWhichSourcesAreReadable(string $envVar): void
    {
        $this->seedAndLogin();
        $id = $this->saveTarget($envVar);

        $body = $this->call('GET', '/web/connections/'.$id.'/diagnostics');

        self::assertSame(200, $this->statusCode());
        self::assertSame(['sessions' => ['availability' => 'available', 'reason' => null, 'privilege' => null]], $body['capabilities']);
    }

    #[DataProvider('targets')]
    public function testListsTheSessionsOfTheServer(string $envVar): void
    {
        $this->seedAndLogin();
        $id = $this->saveTarget($envVar);

        $body = $this->call('GET', '/web/connections/'.$id.'/diagnostics/sessions');

        self::assertSame(200, $this->statusCode());
        self::assertSame('available', self::arrayOf($body['status'])['availability']);
        self::assertFalse($body['truncated']);
        $sessions = $body['sessions'];
        self::assertIsArray($sessions);
        $current = array_filter($sessions, static fn (mixed $s): bool => \is_array($s) && true === $s['current']);
        self::assertCount(1, $current);
        $first = self::arrayOf(reset($current));
        self::assertSame(['id', 'user', 'client', 'database', 'state', 'seconds', 'query', 'queryReason', 'current'], array_keys($first));
    }

    public function testAnotherDatabaseCanBeNamed(): void
    {
        $this->seedAndLogin();
        $id = $this->saveTarget('TARGET_POSTGRES_URL');

        $this->call('GET', '/web/connections/'.$id.'/diagnostics/sessions?database=postgres');

        self::assertSame(200, $this->statusCode());
    }

    public function testRedisIsUnsupported(): void
    {
        RedisTarget::seeded();
        $this->seedAndLogin();
        $parameters = TargetDatabase::parameters(RedisTarget::ENV);
        $created = $this->call('POST', '/web/connections', ['name' => 'Cache', 'engine' => 'redis', 'host' => $parameters->host, 'port' => $parameters->port, 'database' => $parameters->database, 'username' => $parameters->username, 'password' => $parameters->password()]);
        $id = self::arrayOf($created['connection'])['id'];
        self::assertIsString($id);

        $capabilities = $this->call('GET', '/web/connections/'.$id.'/diagnostics');
        self::assertSame(200, $this->statusCode());
        self::assertSame('unsupported', self::arrayOf(self::arrayOf($capabilities['capabilities'])['sessions'])['availability']);

        $this->call('GET', '/web/connections/'.$id.'/diagnostics/sessions');
        self::assertSame(501, $this->statusCode());
        self::assertSame('unsupported_operation', $this->call('GET', '/web/connections/'.$id.'/diagnostics/sessions')['error']);
    }

    public function testAnotherAccountsConnectionIsNotFound(): void
    {
        $this->seedUser('alice');
        $this->seedUser('bob');
        $this->login('alice');
        $id = $this->saveTarget('TARGET_POSTGRES_URL');
        $this->call('POST', '/web/logout');
        $this->login('bob');

        $this->call('GET', '/web/connections/'.$id.'/diagnostics/sessions');

        self::assertSame(404, $this->statusCode());
    }

    public function testAuditNotesWhoReadWhatButNeverTheQueryTexts(): void
    {
        $user = $this->seedAndLogin();
        $id = $this->saveTarget('TARGET_POSTGRES_URL');

        $this->call('GET', '/web/connections/'.$id.'/diagnostics/sessions');

        $events = array_values(array_filter($this->auditEvents($user, 'diagnostic'), static fn ($e): bool => AuditAction::DiagnosticRead === $e->getAction()));
        self::assertCount(1, $events);
        $details = $events[0]->getDetails();
        self::assertSame('sessions', $details['topic']);
        self::assertSame('postgresql', $details['engine']);
        self::assertSame('available', $details['status']);
        self::assertIsInt($details['rows']);
        self::assertGreaterThanOrEqual(1, $details['rows']);
        self::assertStringNotContainsString('pg_stat_activity', json_encode($details, \JSON_THROW_ON_ERROR));
        self::assertSame($id, $events[0]->getSubjectId()?->toRfc4122());
    }
}
