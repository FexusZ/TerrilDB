<?php

declare(strict_types=1);

namespace App\Tests\Integration\Web;

use App\Tests\Integration\Driver\TargetDatabase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

#[Group('integration')]
final class ApiV1Test extends WebApiTestCase
{
    private string $connectionId = '';
    private string $otherConnectionId = '';
    private string $tokenId = '';
    private string $token = '';

    /**
     * @return iterable<string, array{string}>
     */
    public static function targets(): iterable
    {
        yield from TargetDatabase::all();
    }

    /**
     * Un compte avec deux connexions et un jeton qui n'ouvre que la première, puis un client sans aucun cookie.
     */
    /**
     * @param array<string, bool> $rights droits du jeton (writeData, writeSchema, createConnections)
     */
    private function prepare(string $envVar = 'TARGET_POSTGRES_URL', int $lifetimeDays = 30, array $rights = []): \App\Entity\User
    {
        $user = $this->seedAndLogin();
        $parameters = TargetDatabase::parameters($envVar);
        $body = ['name' => 'Target', 'engine' => TargetDatabase::engine($envVar)->value, 'host' => $parameters->host, 'port' => $parameters->port, 'database' => $parameters->database, 'username' => $parameters->username, 'password' => $parameters->password()];
        $this->connectionId = $this->idOf($this->call('POST', '/web/connections', $body)['connection']);
        $this->otherConnectionId = $this->idOf($this->call('POST', '/web/connections', ['name' => 'Other'] + $body)['connection']);

        $created = $this->call('POST', '/web/tokens', ['name' => 'Assistant', 'connectionIds' => [$this->connectionId], 'lifetimeDays' => $lifetimeDays] + $rights);
        self::assertSame(201, $this->statusCode());
        self::assertIsString($created['token']);
        $this->token = $created['token'];
        $this->tokenId = $this->idOf($created['tokenInfo']);

        $this->client->getCookieJar()->clear();

        return $user;
    }

    private function idOf(mixed $entity): string
    {
        $id = self::arrayOf($entity)['id'];
        self::assertIsString($id);

        return $id;
    }

    /**
     * @param array<string, mixed> $body
     *
     * @return array<string, mixed>
     */
    private function api(string $method, string $uri, array $body = [], ?string $token = null): array
    {
        $token ??= $this->token;

        return $this->call($method, $uri, $body, csrf: false, extraServer: '' === $token ? [] : ['HTTP_AUTHORIZATION' => 'Bearer '.$token]);
    }

    public function testNoTokenIsRefusedWithAChallenge(): void
    {
        $body = $this->api('GET', '/api/v1/connections', token: '');

        self::assertSame(401, $this->statusCode());
        self::assertSame(['error' => 'invalid_token'], $body);
        self::assertSame('Bearer', $this->client->getResponse()->headers->get('WWW-Authenticate'));
    }

    public function testAPlainTokenReadsARedisConnectionButCannotWriteOrBrowseTables(): void
    {
        $seed = \App\Tests\Integration\Driver\RedisTarget::seeded();
        try {
            $this->prepare(\App\Tests\Integration\Driver\RedisTarget::ENV);
            $path = '/api/v1/connections/'.$this->connectionId;

            $read = $this->api('POST', $path.'/query', ['sql' => 'get user:1']);
            self::assertSame(200, $this->statusCode());
            self::assertSame([['Alice']], $read['rows']);

            $write = $this->api('POST', $path.'/query', ['sql' => 'set user:1 hacked']);
            self::assertSame(403, $this->statusCode());
            self::assertSame('query_refused', $write['error']);
            self::assertSame('Alice', $seed->get('user:1'));

            $this->api('GET', $path.'/tables');
            self::assertSame(501, $this->statusCode());
        } finally {
            $seed->flushdb();
        }
    }

    public function testBadTokensAreRefusedIdentically(): void
    {
        $this->prepare();
        $wrongSecret = substr($this->token, 0, -1).('a' === substr($this->token, -1) ? 'b' : 'a');

        $responses = [];
        foreach (['garbage', $wrongSecret, 'tdb_'.str_repeat('0', 32).'_'.str_repeat('a', 43), 'Bearer '.$this->token] as $candidate) {
            $responses[] = $this->api('GET', '/api/v1/connections', token: $candidate);
            self::assertSame(401, $this->statusCode(), $candidate);
        }
        self::assertCount(1, array_unique(array_map(static fn (array $r): string => json_encode($r, \JSON_THROW_ON_ERROR), $responses)));
    }

    public function testOtherAuthorizationSchemesAreRefused(): void
    {
        $this->prepare();

        $this->call('GET', '/api/v1/connections', csrf: false, extraServer: ['HTTP_AUTHORIZATION' => 'Basic '.base64_encode('alice:'.self::PASSWORD)]);

        self::assertSame(401, $this->statusCode());
    }

    public function testAValidTokenDescribesItself(): void
    {
        $this->prepare();

        $body = $this->api('GET', '/api/v1/token');

        self::assertSame(200, $this->statusCode());
        $token = self::arrayOf($body['token']);
        self::assertSame($this->tokenId, $token['id']);
        self::assertSame('Assistant', $token['name']);
        self::assertSame([$this->connectionId], $token['connectionIds']);
        self::assertSame('alice', self::arrayOf($body['user'])['username']);
    }

    public function testResponsesAreNeverCached(): void
    {
        $this->prepare();

        $this->api('GET', '/api/v1/token');

        self::assertStringContainsString('no-store', (string) $this->client->getResponse()->headers->get('Cache-Control'));
        self::assertSame('nosniff', $this->client->getResponse()->headers->get('X-Content-Type-Options'));
    }

    public function testOnlyTheGrantedConnectionsAreListed(): void
    {
        $this->prepare();

        $body = $this->api('GET', '/api/v1/connections');

        self::assertSame(200, $this->statusCode());
        $connections = self::arrayOf($body['connections']);
        self::assertCount(1, $connections);
        self::assertSame(['id' => $this->connectionId, 'name' => 'Target', 'engine' => 'postgresql'], self::arrayOf($connections[0]));
        self::assertStringNotContainsString('db.exemple', json_encode($body, \JSON_THROW_ON_ERROR));
    }

    #[DataProvider('targets')]
    public function testReadsTheSchemaOfAGrantedConnection(string $envVar): void
    {
        $this->prepare($envVar);

        $tables = $this->api('GET', '/api/v1/connections/'.$this->connectionId.'/tables');
        self::assertSame(200, $this->statusCode());
        self::assertCount(7, self::arrayOf($tables['tables']));

        $table = $this->api('GET', '/api/v1/connections/'.$this->connectionId.'/tables/order_items');
        self::assertSame(200, $this->statusCode());
        self::assertSame('order_items', self::arrayOf($table['table'])['name']);
    }

    #[DataProvider('targets')]
    public function testRunsReadOnlyQueries(string $envVar): void
    {
        $this->prepare($envVar);

        $body = $this->api('POST', '/api/v1/connections/'.$this->connectionId.'/query', ['sql' => 'select id, name from categories order by id', 'maxRows' => 3]);

        self::assertSame(200, $this->statusCode());
        self::assertSame(['id', 'name'], $body['columns']);
        self::assertSame(3, $body['rowCount']);
        self::assertTrue($body['truncated']);
    }

    public function testNothingOutsideTheGrantIsReachable(): void
    {
        $this->prepare();

        foreach ([['GET', '/tables'], ['GET', '/tables/orders'], ['POST', '/query']] as [$method, $suffix]) {
            $this->api($method, '/api/v1/connections/'.$this->otherConnectionId.$suffix, 'POST' === $method ? ['sql' => 'select 1'] : []);
            self::assertSame(404, $this->statusCode(), $method.' '.$suffix);
        }
        $this->api('GET', '/api/v1/connections/00000000-0000-7000-8000-000000000000/tables');
        self::assertSame(404, $this->statusCode());
    }

    public function testWritesAreRefusedWhateverIsAsked(): void
    {
        $this->prepare();
        $path = '/api/v1/connections/'.$this->connectionId.'/query';

        $write = $this->api('POST', $path, ['sql' => "insert into categories (name) values ('sneaky')"]);
        self::assertSame(403, $this->statusCode());
        self::assertSame(['error' => 'query_refused', 'reason' => 'not_read_only'], $write);

        $destructive = $this->api('POST', $path, ['sql' => 'delete from categories', 'confirmed' => true]);
        self::assertSame(403, $this->statusCode());
        self::assertSame('not_read_only', $destructive['reason']);

        $this->api('POST', $path, ['sql' => 'select 1', 'mode' => 'read_write']);
        self::assertSame(422, $this->statusCode());

        $count = $this->api('POST', $path, ['sql' => "select count(*) from categories where name = 'sneaky'"]);
        self::assertEquals(0, self::arrayOf(self::arrayOf($count['rows'])[0])[0]);
    }

    public function testAPlainTokenCannotExecuteAnything(): void
    {
        $this->prepare();

        $body = $this->api('POST', '/api/v1/connections/'.$this->connectionId.'/execute', ['sql' => 'select 1']);

        self::assertSame(403, $this->statusCode());
        self::assertSame(['error' => 'permission_denied'], $body);
    }

    public function testADataTokenRunsAWriteThroughExecute(): void
    {
        $this->prepare(rights: ['writeData' => true]);
        $path = '/api/v1/connections/'.$this->connectionId.'/execute';

        $body = $this->api('POST', $path, ['sql' => 'update categories set name = name where id = 1']);

        self::assertSame(200, $this->statusCode());
        self::assertSame(1, $body['affectedRows']);
    }

    public function testADataTokenCannotChangeTheSchema(): void
    {
        $this->prepare(rights: ['writeData' => true]);
        $path = '/api/v1/connections/'.$this->connectionId.'/execute';

        $body = $this->api('POST', $path, ['sql' => 'create table sneaky (a int)']);

        self::assertSame(403, $this->statusCode());
        self::assertSame(['error' => 'query_refused', 'reason' => 'write_not_permitted'], $body);
        $drop = $this->api('POST', $path, ['sql' => 'drop table categories', 'confirmed' => true]);
        self::assertSame(403, $this->statusCode());
        self::assertSame('write_not_permitted', $drop['reason']);
    }

    public function testASchemaTokenCannotChangeRows(): void
    {
        $this->prepare(rights: ['writeSchema' => true]);

        $body = $this->api('POST', '/api/v1/connections/'.$this->connectionId.'/execute', ['sql' => 'update categories set name = name where id = 1']);

        self::assertSame(403, $this->statusCode());
        self::assertSame('write_not_permitted', $body['reason']);
    }

    public function testADestructiveStatementNeedsExplicitConfirmation(): void
    {
        $this->prepare(rights: ['writeData' => true]);
        $path = '/api/v1/connections/'.$this->connectionId.'/execute';

        $asked = $this->api('POST', $path, ['sql' => 'update categories set name = name']);
        self::assertSame(409, $this->statusCode());
        self::assertSame(['error' => 'confirmation_required', 'reason' => 'destructive_without_where'], $asked);

        $confirmed = $this->api('POST', $path, ['sql' => 'update categories set name = name', 'confirmed' => true]);
        self::assertSame(200, $this->statusCode());
        self::assertGreaterThan(0, $confirmed['affectedRows']);
    }

    public function testTheQueryRouteStaysReadOnlyEvenForAWritingToken(): void
    {
        $this->prepare(rights: ['writeData' => true, 'writeSchema' => true]);

        $body = $this->api('POST', '/api/v1/connections/'.$this->connectionId.'/query', ['sql' => 'update categories set name = name where id = 1']);

        self::assertSame(403, $this->statusCode());
        self::assertSame('not_read_only', $body['reason']);
    }

    public function testExecuteOnlyOpensTheGrantedConnections(): void
    {
        $this->prepare(rights: ['writeData' => true]);

        $this->api('POST', '/api/v1/connections/'.$this->otherConnectionId.'/execute', ['sql' => 'select 1']);

        self::assertSame(404, $this->statusCode());
    }

    public function testAWritingTokenWritesToRedisOnlyWithTheDataRight(): void
    {
        $seed = \App\Tests\Integration\Driver\RedisTarget::seeded();
        try {
            $this->prepare(\App\Tests\Integration\Driver\RedisTarget::ENV, rights: ['writeData' => true]);
            $path = '/api/v1/connections/'.$this->connectionId.'/execute';

            $this->api('POST', $path, ['sql' => 'set note hello']);
            self::assertSame(200, $this->statusCode());
            self::assertSame('hello', $seed->get('note'));

            $flush = $this->api('POST', $path, ['sql' => 'flushdb']);
            self::assertSame(409, $this->statusCode());
            self::assertSame('confirmation_required', $flush['error']);
        } finally {
            $seed->flushdb();
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function targetBody(string $name = 'Created by token'): array
    {
        $parameters = TargetDatabase::parameters('TARGET_POSTGRES_URL');

        return ['name' => $name, 'engine' => TargetDatabase::engine('TARGET_POSTGRES_URL')->value, 'host' => $parameters->host, 'port' => $parameters->port, 'database' => $parameters->database, 'username' => $parameters->username, 'password' => $parameters->password()];
    }

    public function testAPlainTokenCannotCreateAConnection(): void
    {
        $this->prepare();

        $body = $this->api('POST', '/api/v1/connections', $this->targetBody());

        self::assertSame(403, $this->statusCode());
        self::assertSame(['error' => 'permission_denied'], $body);
    }

    public function testATokenWithTheRightCreatesAConnectionItCanThenUse(): void
    {
        $this->prepare(rights: ['createConnections' => true]);

        $created = $this->api('POST', '/api/v1/connections', $this->targetBody());

        self::assertSame(201, $this->statusCode());
        $connection = self::arrayOf($created['connection']);
        self::assertSame('Created by token', $connection['name']);
        self::assertSame('postgresql', $connection['engine']);
        self::assertStringNotContainsString(TargetDatabase::parameters('TARGET_POSTGRES_URL')->password(), json_encode($created, \JSON_THROW_ON_ERROR));

        $listed = array_map(static fn (mixed $row): mixed => self::arrayOf($row)['id'], self::arrayOf($this->api('GET', '/api/v1/connections')['connections']));
        self::assertContains($connection['id'], $listed);

        self::assertIsString($connection['id']);
        $query = $this->api('POST', '/api/v1/connections/'.$connection['id'].'/query', ['sql' => 'select count(*) from categories']);
        self::assertSame(200, $this->statusCode());
        self::assertNotEmpty($query['rows']);
    }

    public function testAnAllConnectionsTokenAlsoSeesWhatItCreates(): void
    {
        $this->seedAndLogin();
        $created = $this->call('POST', '/web/tokens', ['name' => 'Tout', 'allConnections' => true, 'createConnections' => true]);
        self::assertSame(201, $this->statusCode());
        self::assertIsString($created['token']);
        $this->client->getCookieJar()->clear();

        $new = $this->api('POST', '/api/v1/connections', $this->targetBody(), $created['token']);
        self::assertSame(201, $this->statusCode());

        $listed = $this->api('GET', '/api/v1/connections', token: $created['token']);
        self::assertCount(1, self::arrayOf($listed['connections']));
        self::assertSame(self::arrayOf($new['connection'])['id'], self::arrayOf(self::arrayOf($listed['connections'])[0])['id']);
    }

    public function testCreatingAConnectionRefusesBadInput(): void
    {
        $this->prepare(rights: ['createConnections' => true]);

        $this->api('POST', '/api/v1/connections', ['name' => 'X'] + ['engine' => 'nope'] + $this->targetBody());
        self::assertSame(422, $this->statusCode());
        $this->api('POST', '/api/v1/connections', ['name' => 'X', 'engine' => 'postgresql']);
        self::assertSame(400, $this->statusCode());
    }

    public function testCreatingAConnectionIsAuditedAsAnApiAction(): void
    {
        $this->prepare(rights: ['createConnections' => true]);
        $this->api('POST', '/api/v1/connections', $this->targetBody());

        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $row = $entityManager->getConnection()->fetchAssociative("SELECT actor, token_id FROM audit_event WHERE action = 'connection.created' AND token_id IS NOT NULL ORDER BY occurred_at DESC LIMIT 1");

        self::assertIsArray($row);
        self::assertSame('api', $row['actor']);
        self::assertSame($this->tokenId, $row['token_id']);
    }

    public function testConnectionCreationIsCappedPerToken(): void
    {
        $this->prepare(rights: ['createConnections' => true]);

        for ($i = 1; $i <= 20; ++$i) {
            $this->api('POST', '/api/v1/connections', $this->targetBody('Created '.$i));
            self::assertSame(201, $this->statusCode(), 'creation '.$i);
        }
        $body = $this->api('POST', '/api/v1/connections', $this->targetBody('One too many'));

        self::assertSame(429, $this->statusCode());
        self::assertSame('too_many_attempts', $body['error']);
        self::assertArrayHasKey('retryAfter', $body);
    }

    public function testAHiddenSecondStatementIsRefused(): void
    {
        $this->prepare('TARGET_MYSQL_URL');

        $body = $this->api('POST', '/api/v1/connections/'.$this->connectionId.'/query', ['sql' => 'select 1 /*! ; drop table categories */']);

        self::assertSame(403, $this->statusCode());
        self::assertSame('multiple_statements', $body['reason']);
    }

    public function testASessionCookieDoesNotOpenTheApi(): void
    {
        $this->prepare();
        $this->login();

        $this->call('GET', '/api/v1/connections', csrf: false);

        self::assertSame(401, $this->statusCode());
    }

    public function testATokenDoesNotOpenTheWebApi(): void
    {
        $this->prepare();

        $this->api('GET', '/web/me');
        self::assertSame(401, $this->statusCode());
        $this->api('GET', '/web/connections');
        self::assertSame(401, $this->statusCode());
    }

    public function testAnExpiredTokenIsRefused(): void
    {
        $this->prepare();
        $this->api('GET', '/api/v1/token');
        self::assertSame(200, $this->statusCode());

        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $entityManager->getConnection()->executeStatement("UPDATE api_token SET expires_at = now() - interval '1 minute'");

        $this->api('GET', '/api/v1/token');
        self::assertSame(401, $this->statusCode());
    }

    public function testARevokedTokenIsRefused(): void
    {
        $this->prepare();
        $this->login();
        $this->call('DELETE', '/web/tokens/'.$this->tokenId);
        self::assertSame(204, $this->statusCode());
        $this->client->getCookieJar()->clear();

        $this->api('GET', '/api/v1/token');

        self::assertSame(401, $this->statusCode());
    }

    public function testChangingThePasswordKillsTheTokens(): void
    {
        $this->prepare();
        $this->login();
        $this->call('POST', '/web/account/password', ['current' => self::PASSWORD, 'new' => 'a brand new passphrase']);
        self::assertSame(200, $this->statusCode());
        $this->client->getCookieJar()->clear();

        $this->api('GET', '/api/v1/token');

        self::assertSame(401, $this->statusCode());
    }

    public function testDeletingAConnectionStopsTheTokenFromOpeningIt(): void
    {
        $this->prepare();
        $this->login();
        $this->call('DELETE', '/web/connections/'.$this->connectionId);
        $this->client->getCookieJar()->clear();

        $this->api('GET', '/api/v1/connections/'.$this->connectionId.'/tables');
        self::assertSame(404, $this->statusCode());
        $list = $this->api('GET', '/api/v1/connections');
        self::assertSame([], $list['connections']);
    }

    public function testTheLastUseIsRecorded(): void
    {
        $this->prepare();
        $this->api('GET', '/api/v1/token');
        $this->login();

        $list = $this->call('GET', '/web/tokens');

        self::assertIsString(self::arrayOf(self::arrayOf($list['tokens'])[0])['lastUsedAt']);
    }

    public function testTooManyFailuresFromOneAddressAreThrottled(): void
    {
        for ($i = 0; $i < 30; ++$i) {
            $this->api('GET', '/api/v1/connections', token: 'tdb_'.str_repeat('1', 32).'_'.str_repeat('b', 43));
            self::assertSame(401, $this->statusCode());
        }
        $body = $this->api('GET', '/api/v1/connections', token: 'tdb_'.str_repeat('1', 32).'_'.str_repeat('b', 43));

        self::assertSame(429, $this->statusCode());
        self::assertSame('too_many_attempts', $body['error']);
        self::assertTrue($this->client->getResponse()->headers->has('Retry-After'));
    }

    public function testATokenHasAUsageBudget(): void
    {
        $this->prepare();

        for ($i = 0; $i < 120; ++$i) {
            $this->api('GET', '/api/v1/token');
            self::assertSame(200, $this->statusCode(), 'request '.$i);
        }
        $body = $this->api('GET', '/api/v1/token');

        self::assertSame(429, $this->statusCode());
        self::assertSame('too_many_attempts', $body['error']);
    }

    public function testATokenWithoutTheDiagnosticRightSeesNothingOfTheServer(): void
    {
        $this->prepare(rights: ['writeData' => true, 'writeSchema' => true, 'createConnections' => true]);

        $capabilities = $this->api('GET', '/api/v1/connections/'.$this->connectionId.'/diagnostics');
        self::assertSame(403, $this->statusCode());
        self::assertSame(['error' => 'permission_denied'], $capabilities);

        $sessions = $this->api('GET', '/api/v1/connections/'.$this->connectionId.'/diagnostics/sessions');
        self::assertSame(403, $this->statusCode());
        self::assertSame(['error' => 'permission_denied'], $sessions);
    }

    #[DataProvider('targets')]
    public function testATokenWithTheDiagnosticRightListsTheSessions(string $envVar): void
    {
        $this->prepare($envVar, rights: ['diagnostic' => true]);

        $capabilities = $this->api('GET', '/api/v1/connections/'.$this->connectionId.'/diagnostics');
        self::assertSame(200, $this->statusCode());
        self::assertSame(['sessions' => ['availability' => 'available', 'reason' => null, 'privilege' => null]], $capabilities['capabilities']);

        $body = $this->api('GET', '/api/v1/connections/'.$this->connectionId.'/diagnostics/sessions');
        self::assertSame(200, $this->statusCode());
        self::assertSame('available', self::arrayOf($body['status'])['availability']);
        self::assertIsArray($body['sessions']);
        self::assertNotEmpty($body['sessions']);
    }

    public function testTheDiagnosticRightDoesNotOpenAConnectionOutsideTheList(): void
    {
        $this->prepare(rights: ['diagnostic' => true]);

        $this->api('GET', '/api/v1/connections/'.$this->otherConnectionId.'/diagnostics/sessions');

        self::assertSame(404, $this->statusCode());
    }

    public function testTheDiagnosticRightDoesNotWrite(): void
    {
        $this->prepare(rights: ['diagnostic' => true]);

        $this->api('POST', '/api/v1/connections/'.$this->connectionId.'/execute', ['sql' => 'update categories set name = name where id = 1']);

        self::assertSame(403, $this->statusCode());
    }

    public function testTheReadIsAuditedAsTheApiWithoutTheTexts(): void
    {
        $user = $this->prepare(rights: ['diagnostic' => true]);

        $this->api('GET', '/api/v1/connections/'.$this->connectionId.'/diagnostics/sessions');

        $events = $this->auditEvents($user, 'diagnostic');
        self::assertCount(1, $events);
        self::assertSame('api', $events[0]->getActor()->value);
        self::assertSame($this->tokenId, $events[0]->getTokenId()?->toRfc4122());
        self::assertArrayNotHasKey('sql', $events[0]->getDetails());
        self::assertArrayNotHasKey('query', $events[0]->getDetails());
    }
}
