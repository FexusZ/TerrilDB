<?php

declare(strict_types=1);

namespace App\Tests\Integration\Web;

use App\Entity\User;
use App\Tests\Integration\Driver\TargetDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * Les droits de lecture « readSchema » (bases et schémas) et « export » sur l'API à jetons : refusés par défaut,
 * indépendants l'un de l'autre, limités aux connexions du jeton, sans jamais écrire.
 */
#[Group('integration')]
final class ApiReadRightsTest extends WebApiTestCase
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
     * @param array<string, bool> $rights
     */
    private function prepare(string $envVar = 'TARGET_POSTGRES_URL', array $rights = []): User
    {
        $user = $this->seedAndLogin();
        $parameters = TargetDatabase::parameters($envVar);
        $body = ['name' => 'Target', 'engine' => TargetDatabase::engine($envVar)->value, 'host' => $parameters->host, 'port' => $parameters->port, 'database' => $parameters->database, 'username' => $parameters->username, 'password' => $parameters->password()];
        $this->connectionId = self::idOf($this->call('POST', '/web/connections', $body)['connection']);
        $this->otherConnectionId = self::idOf($this->call('POST', '/web/connections', ['name' => 'Other'] + $body)['connection']);
        $created = $this->call('POST', '/web/tokens', ['name' => 'Assistant', 'connectionIds' => [$this->connectionId], 'lifetimeDays' => 30] + $rights);
        self::assertSame(201, $this->statusCode());
        self::assertIsString($created['token']);
        $this->token = $created['token'];
        $this->tokenId = self::idOf($created['tokenInfo']);
        $this->client->getCookieJar()->clear();

        return $user;
    }

    private static function idOf(mixed $entity): string
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
    private function api(string $method, string $uri, array $body = []): array
    {
        return $this->call($method, $uri, $body, csrf: false, extraServer: ['HTTP_AUTHORIZATION' => 'Bearer '.$this->token]);
    }

    /**
     * @param array<string, mixed> $body
     */
    private function export(string $connection, array $body): string
    {
        $this->client->request('POST', '/api/v1/connections/'.$connection.'/export', server: ['CONTENT_TYPE' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer '.$this->token], content: json_encode($body, \JSON_THROW_ON_ERROR));

        return (string) $this->client->getResponse()->getContent();
    }

    public function testWithoutTheRightsNothingOfTheseRoutesIsServed(): void
    {
        $this->prepare(rights: ['writeData' => true, 'writeSchema' => true, 'createConnections' => true, 'diagnostic' => true, 'singleStep' => true]);
        $path = '/api/v1/connections/'.$this->connectionId;

        self::assertSame(['error' => 'permission_denied'], $this->api('GET', $path.'/databases'));
        self::assertSame(403, $this->statusCode());
        self::assertSame(['error' => 'permission_denied'], $this->api('GET', $path.'/schemas'));
        self::assertSame(403, $this->statusCode());
        $this->export($this->connectionId, ['sql' => 'select 1', 'format' => 'csv']);
        self::assertSame(403, $this->statusCode());
    }

    public function testTheSchemaRightDoesNotGiveTheExport(): void
    {
        $this->prepare(rights: ['readSchema' => true]);

        $this->export($this->connectionId, ['sql' => 'select 1', 'format' => 'csv']);

        self::assertSame(403, $this->statusCode());
    }

    public function testTheExportRightDoesNotGiveTheSchema(): void
    {
        $this->prepare(rights: ['export' => true]);
        $this->api('GET', '/api/v1/connections/'.$this->connectionId.'/databases');
        self::assertSame(403, $this->statusCode());
    }

    #[DataProvider('targets')]
    public function testListsTheDatabasesWithTheSchemaRight(string $envVar): void
    {
        $this->prepare($envVar, ['readSchema' => true]);

        $body = $this->api('GET', '/api/v1/connections/'.$this->connectionId.'/databases');

        self::assertSame(200, $this->statusCode());
        $names = array_map(static fn (mixed $row): mixed => self::arrayOf($row)['name'], self::arrayOf($body['databases']));
        self::assertContains('shop', $names);
        self::assertArrayHasKey('default', $body);
        foreach (self::arrayOf($body['databases']) as $row) {
            self::assertFalse(self::arrayOf($row)['system'], 'les bases du moteur sont masquées par défaut');
        }
    }

    public function testListsTheSchemasOfPostgreSqlWithoutLeakingOtherConnections(): void
    {
        $this->prepare('TARGET_POSTGRES_URL', ['readSchema' => true]);
        $body = $this->api('GET', '/api/v1/connections/'.$this->connectionId.'/schemas');
        self::assertSame(200, $this->statusCode());
        $names = array_map(static fn (mixed $row): mixed => self::arrayOf($row)['name'], self::arrayOf($body['schemas']));
        self::assertContains('public', $names);

        $this->api('GET', '/api/v1/connections/'.$this->otherConnectionId.'/schemas');
        self::assertSame(404, $this->statusCode());
        $this->api('GET', '/api/v1/connections/'.$this->otherConnectionId.'/databases');
        self::assertSame(404, $this->statusCode());
    }

    public function testSchemasAreNotAvailableOnMySql(): void
    {
        $this->prepare('TARGET_MYSQL_URL', ['readSchema' => true]);
        $this->api('GET', '/api/v1/connections/'.$this->connectionId.'/schemas');
        self::assertSame(501, $this->statusCode());
    }

    public function testTheSchemaRightDoesNotWriteNorExport(): void
    {
        $this->prepare(rights: ['readSchema' => true]);

        $this->api('POST', '/api/v1/connections/'.$this->connectionId.'/execute', ['sql' => 'update categories set name = name where id = 1']);

        self::assertSame(403, $this->statusCode());
    }

    public function testTheSchemaReadIsAuditedAsTheApi(): void
    {
        $user = $this->prepare(rights: ['readSchema' => true]);

        $this->api('GET', '/api/v1/connections/'.$this->connectionId.'/databases');

        $events = $this->auditEvents($user, 'schema');
        self::assertCount(1, $events);
        self::assertSame('schema.read', $events[0]->getAction()->value);
        self::assertSame('api', $events[0]->getActor()->value);
        self::assertSame($this->tokenId, $events[0]->getTokenId()?->toRfc4122());
        self::assertSame('databases', $events[0]->getDetails()['topic']);
    }

    #[DataProvider('targets')]
    public function testExportsACsvFileWithTheExportRight(string $envVar): void
    {
        $this->prepare($envVar, ['export' => true]);

        $csv = $this->export($this->connectionId, ['sql' => 'select id, name from categories order by id', 'format' => 'csv', 'maxRows' => 3]);

        self::assertSame(200, $this->statusCode());
        $lines = explode("\r\n", rtrim($csv, "\r\n"));
        self::assertSame('id,name', $lines[0]);
        self::assertCount(4, $lines);
        $response = $this->client->getResponse();
        self::assertStringStartsWith('text/csv', (string) $response->headers->get('Content-Type'));
        self::assertSame('1', $response->headers->get('X-TerrilDB-Truncated'));
    }

    public function testExportsJson(): void
    {
        $this->prepare(rights: ['export' => true]);

        $json = $this->export($this->connectionId, ['sql' => 'select id, name from categories order by id', 'format' => 'json', 'maxRows' => 2]);

        self::assertSame(200, $this->statusCode());
        $rows = json_decode($json, true);
        self::assertIsArray($rows);
        self::assertCount(2, $rows);
    }

    public function testTheExportStaysReadOnlyAndInsideTheTokenConnections(): void
    {
        $this->prepare(rights: ['export' => true]);

        $refused = $this->export($this->connectionId, ['sql' => 'delete from categories', 'format' => 'csv']);
        self::assertSame(403, $this->statusCode());
        self::assertStringContainsString('query_refused', $refused);

        $this->export($this->otherConnectionId, ['sql' => 'select 1', 'format' => 'csv']);
        self::assertSame(404, $this->statusCode());

        $this->export($this->connectionId, ['sql' => 'select 1', 'format' => 'xml']);
        self::assertSame(400, $this->statusCode());
    }

    public function testTheExportIsAuditedAsAnApiQueryWithItsPurpose(): void
    {
        $user = $this->prepare(rights: ['export' => true]);

        $this->export($this->connectionId, ['sql' => 'select id from categories', 'format' => 'csv', 'maxRows' => 2]);

        $events = $this->auditEvents($user, 'query');
        self::assertCount(1, $events);
        self::assertSame('api', $events[0]->getActor()->value);
        self::assertSame($this->tokenId, $events[0]->getTokenId()?->toRfc4122());
        self::assertSame('export', $events[0]->getDetails()['purpose']);
        self::assertSame('csv', $events[0]->getDetails()['format']);
    }
}
