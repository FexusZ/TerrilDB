<?php

declare(strict_types=1);

namespace App\Tests\Integration\Web;

use App\Audit\AuditAction;
use App\Tests\Integration\Driver\LockScenario;
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
        $locks = 'TARGET_CLICKHOUSE_URL' === $envVar ? ['availability' => 'unsupported', 'reason' => 'not_supported', 'privilege' => null] : ['availability' => 'available', 'reason' => null, 'privilege' => null];
        self::assertSame(['sessions' => ['availability' => 'available', 'reason' => null, 'privilege' => null], 'locks' => $locks, 'variables' => ['availability' => 'available', 'reason' => null, 'privilege' => null]], $body['capabilities']);
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
        self::assertSame(['id', 'user', 'client', 'database', 'state', 'seconds', 'query', 'current'], array_keys($first));
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
        self::assertSame('unsupported', self::arrayOf(self::arrayOf($capabilities['capabilities'])['locks'])['availability']);

        self::assertSame('unsupported', self::arrayOf(self::arrayOf($capabilities['capabilities'])['variables'])['availability']);

        $this->call('GET', '/web/connections/'.$id.'/diagnostics/variables');
        self::assertSame(501, $this->statusCode());

        $this->call('GET', '/web/connections/'.$id.'/diagnostics/locks');
        self::assertSame(501, $this->statusCode());

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

    /**
     * @return iterable<string, array{string}>
     */
    public static function relational(): iterable
    {
        yield from TargetDatabase::relational();
    }

    public function testLocksRequireASession(): void
    {
        $this->call('GET', '/web/connections/00000000-0000-7000-8000-000000000000/diagnostics/locks');

        self::assertSame(401, $this->statusCode());
    }

    #[DataProvider('relational')]
    public function testShowsWhoBlocksWhomAndTheOpenTransactions(string $envVar): void
    {
        $this->seedAndLogin();
        $id = $this->saveTarget($envVar);
        $scenario = LockScenario::begin($envVar);
        try {
            $scenario->startWaiter();
            $body = LockScenario::until(
                fn (): array => $this->call('GET', '/web/connections/'.$id.'/diagnostics/locks'),
                static fn (array $b): bool => [] !== $b['blocking'],
            );

            self::assertSame(200, $this->statusCode());
            self::assertSame('available', self::arrayOf($body['status'])['availability']);
            self::assertFalse($body['truncated']);
            $roots = array_values(array_filter(self::listOf($body['blocking']), static fn (mixed $n): bool => self::arrayOf($n)['id'] === $scenario->holderId()));
            self::assertCount(1, $roots);
            $root = self::arrayOf($roots[0]);
            self::assertSame(['id', 'user', 'query', 'seconds', 'kind', 'mode', 'object', 'blocked'], array_keys($root));
            self::assertNull($root['seconds']);
            $child = self::arrayOf(self::listOf($root['blocked'])[0]);
            self::assertSame($scenario->waiterId(), $child['id']);
            self::assertSame('row', $child['kind']);
            self::assertIsInt($child['seconds']);
            self::assertStringContainsString($scenario->table, self::stringOf($child['object']));
            $transactions = array_map(static fn (mixed $t): mixed => self::arrayOf($t)['session'], self::listOf($body['transactions']));
            self::assertContains($scenario->holderId(), $transactions);
            $first = self::arrayOf(self::listOf($body['transactions'])[0]);
            self::assertSame(['session', 'user', 'state', 'seconds', 'rowsLocked', 'rowsModified', 'sleeping', 'query'], array_keys($first));
        } finally {
            $scenario->cleanup();
        }
    }

    public function testLocksAreAuditedWithoutAnyQueryText(): void
    {
        $user = $this->seedAndLogin();
        $id = $this->saveTarget('TARGET_POSTGRES_URL');
        $scenario = LockScenario::begin('TARGET_POSTGRES_URL');
        try {
            $scenario->startWaiter();
            LockScenario::until(fn (): array => $this->call('GET', '/web/connections/'.$id.'/diagnostics/locks'), static fn (array $b): bool => [] !== $b['blocking']);
        } finally {
            $scenario->cleanup();
        }

        $events = array_values(array_filter($this->auditEvents($user, 'diagnostic'), static fn ($e): bool => 'locks' === ($e->getDetails()['topic'] ?? null)));
        self::assertNotEmpty($events);
        $details = $events[0]->getDetails();
        self::assertSame('available', $details['status']);
        self::assertArrayHasKey('transactions', $details);
        self::assertArrayHasKey('waits', $details);
        self::assertStringNotContainsString('UPDATE', json_encode($details, \JSON_THROW_ON_ERROR));
    }

    public function testClickHouseHasNoLocks(): void
    {
        $this->seedAndLogin();
        $id = $this->saveTarget('TARGET_CLICKHOUSE_URL');

        $body = $this->call('GET', '/web/connections/'.$id.'/diagnostics/locks');

        self::assertSame(501, $this->statusCode());
        self::assertSame('unsupported_operation', $body['error']);
    }

    public function testVariablesRequireASession(): void
    {
        $this->call('GET', '/web/connections/00000000-0000-7000-8000-000000000000/diagnostics/variables');

        self::assertSame(401, $this->statusCode());
    }

    #[DataProvider('targets')]
    public function testListsTheVariablesOfTheServer(string $envVar): void
    {
        $this->seedAndLogin();
        $id = $this->saveTarget($envVar);
        $name = 'TARGET_CLICKHOUSE_URL' === $envVar ? 'max_threads' : 'max_connections';

        $body = $this->call('GET', '/web/connections/'.$id.'/diagnostics/variables?q='.$name);

        self::assertSame(200, $this->statusCode());
        self::assertSame('available', self::arrayOf($body['status'])['availability']);
        self::assertFalse($body['truncated']);
        $variables = $body['variables'];
        self::assertIsArray($variables);
        self::assertNotEmpty($variables);
        $first = self::arrayOf($variables[0]);
        self::assertSame(['name', 'value', 'default', 'changed', 'masked', 'source', 'restart', 'scope', 'unit'], array_keys($first));
    }

    public function testChangedOnlyAndTheLimitAreHonoured(): void
    {
        $this->seedAndLogin();
        $id = $this->saveTarget('TARGET_POSTGRES_URL');

        $body = $this->call('GET', '/web/connections/'.$id.'/diagnostics/variables?changed=1&limit=2');

        self::assertSame(200, $this->statusCode());
        $variables = $body['variables'];
        self::assertIsArray($variables);
        self::assertCount(2, $variables);
        self::assertTrue($body['truncated']);
        foreach ($variables as $variable) {
            self::assertTrue(self::arrayOf($variable)['changed']);
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function badInputs(): iterable
    {
        yield 'zero limit' => ['limit=0'];
        yield 'huge limit' => ['limit=100000'];
        yield 'text limit' => ['limit=abc'];
        yield 'long search' => ['q='.str_repeat('a', 65)];
    }

    #[DataProvider('badInputs')]
    public function testRefusesABadQuery(string $query): void
    {
        $this->seedAndLogin();
        $id = $this->saveTarget('TARGET_POSTGRES_URL');

        $body = $this->call('GET', '/web/connections/'.$id.'/diagnostics/variables?'.$query);

        self::assertSame(422, $this->statusCode());
        self::assertSame('invalid_input', $body['error']);
    }

    public function testASecretIsNeverServed(): void
    {
        $this->seedAndLogin();
        $id = $this->saveTarget('TARGET_POSTGRES_URL');

        $body = $this->call('GET', '/web/connections/'.$id.'/diagnostics/variables?q=ssl_key');

        $variable = self::arrayOf(self::arrayOf($body['variables'])[0]);
        self::assertSame('ssl_key_file', $variable['name']);
        self::assertTrue($variable['masked']);
        self::assertNull($variable['value']);
        self::assertNull($variable['default']);
    }

    public function testVariablesAreAuditedWithoutValuesNorSearchText(): void
    {
        $user = $this->seedAndLogin();
        $id = $this->saveTarget('TARGET_POSTGRES_URL');

        $this->call('GET', '/web/connections/'.$id.'/diagnostics/variables?q=wal_level');

        $events = array_values(array_filter($this->auditEvents($user, 'diagnostic'), static fn ($e): bool => 'variables' === ($e->getDetails()['topic'] ?? null)));
        self::assertCount(1, $events);
        $details = $events[0]->getDetails();
        self::assertSame('available', $details['status']);
        self::assertSame(1, $details['rows']);
        self::assertStringNotContainsString('wal_level', json_encode($details, \JSON_THROW_ON_ERROR));
    }
}
