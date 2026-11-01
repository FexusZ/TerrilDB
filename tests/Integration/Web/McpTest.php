<?php

declare(strict_types=1);

namespace App\Tests\Integration\Web;

use App\Tests\Integration\Driver\TargetDatabase;
use PHPUnit\Framework\Attributes\Group;

#[Group('integration')]
final class McpTest extends WebApiTestCase
{
    private const PROTOCOL = '2025-06-18';

    private string $connectionId = '';
    private string $otherConnectionId = '';
    private string $token = '';
    private string $session = '';

    /**
     * Un compte avec deux connexions et un jeton qui n'ouvre que la première, puis un client sans cookie.
     */
    private function prepare(string $envVar = 'TARGET_POSTGRES_URL'): void
    {
        $this->seedAndLogin();
        $parameters = TargetDatabase::parameters($envVar);
        $body = ['name' => 'Target', 'engine' => TargetDatabase::engine($envVar)->value, 'host' => $parameters->host, 'port' => $parameters->port, 'database' => $parameters->database, 'username' => $parameters->username, 'password' => $parameters->password()];
        $this->connectionId = $this->idOf($this->call('POST', '/web/connections', $body)['connection']);
        $this->otherConnectionId = $this->idOf($this->call('POST', '/web/connections', ['name' => 'Other'] + $body)['connection']);
        $created = $this->call('POST', '/web/tokens', ['name' => 'Assistant', 'connectionIds' => [$this->connectionId]]);
        self::assertIsString($created['token']);
        $this->token = $created['token'];
        $this->client->getCookieJar()->clear();
    }

    /**
     * @return array<string, mixed>
     */
    private static function obj(mixed $value): array
    {
        self::assertIsArray($value);

        return array_combine(array_map('strval', array_keys($value)), $value);
    }

    private static function first(mixed $list): mixed
    {
        self::assertIsArray($list);
        self::assertNotEmpty($list);

        return reset($list);
    }

    private static function str(mixed $value): string
    {
        self::assertIsString($value);

        return $value;
    }

    private function idOf(mixed $entity): string
    {
        $id = self::obj($entity)['id'];
        self::assertIsString($id);

        return $id;
    }

    /**
     * @param array<string, mixed>  $payload
     * @param array<string, string> $headers
     *
     * @return array<string, mixed> le message JSON-RPC rendu (vide pour une notification)
     */
    private function post(array $payload, ?string $token = null, array $headers = []): array
    {
        $token ??= $this->token;
        $server = ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json, text/event-stream', 'HTTP_MCP_PROTOCOL_VERSION' => self::PROTOCOL] + $headers;
        if ('' !== $token) {
            $server['HTTP_AUTHORIZATION'] = 'Bearer '.$token;
        }
        if ('' !== $this->session && !isset($server['HTTP_MCP_SESSION_ID'])) {
            $server['HTTP_MCP_SESSION_ID'] = $this->session;
        }
        $this->client->request('POST', '/mcp', server: $server, content: json_encode($payload, \JSON_THROW_ON_ERROR));

        $response = $this->client->getResponse();
        $content = (string) $response->getContent();
        if ('' === trim($content)) {
            return [];
        }
        if (str_contains((string) $response->headers->get('Content-Type'), 'text/event-stream')) {
            preg_match('/^data: (.+)$/m', $content, $matches);
            $content = $matches[1] ?? '{}';
        }
        $decoded = json_decode($content, true);

        // Un refus du transport (hôte interdit…) est du texte brut, pas un message JSON-RPC.
        return \is_array($decoded) ? self::obj($decoded) : ['raw' => $content];
    }

    private function initialize(): void
    {
        $result = $this->post(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => [
            'protocolVersion' => self::PROTOCOL,
            'capabilities' => new \stdClass(),
            'clientInfo' => ['name' => 'tests', 'version' => '1.0'],
        ]]);
        self::assertSame(200, $this->statusCode(), json_encode($result, \JSON_THROW_ON_ERROR));
        $this->session = $this->client->getResponse()->headers->get('Mcp-Session-Id') ?? '';
        $this->post(['jsonrpc' => '2.0', 'method' => 'notifications/initialized']);
    }

    /**
     * @param array<string, mixed> $arguments
     *
     * @return array<string, mixed> le résultat de l'appel d'outil
     */
    private function callTool(string $name, array $arguments = []): array
    {
        $message = $this->post(['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/call', 'params' => ['name' => $name, 'arguments' => [] === $arguments ? new \stdClass() : $arguments]]);

        return self::obj($message['result'] ?? $message);
    }

    /**
     * @param array<string, mixed> $result
     *
     * @return array<string, mixed>
     */
    private static function textOf(array $result): array
    {
        $content = self::obj(self::first($result['content']));
        self::assertSame('text', $content['type']);
        self::assertIsString($content['text']);

        return self::obj(json_decode($content['text'], true, flags: \JSON_THROW_ON_ERROR));
    }

    public function testAnUnauthenticatedCallIsRefused(): void
    {
        $body = $this->post(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => []], token: '');

        self::assertSame(401, $this->statusCode());
        self::assertSame(['error' => 'invalid_token'], $body);
    }

    public function testAWrongTokenIsRefused(): void
    {
        $this->prepare();

        $this->post(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => []], token: 'tdb_'.str_repeat('0', 32).'_'.str_repeat('a', 43));

        self::assertSame(401, $this->statusCode());
    }

    public function testASessionCookieDoesNotOpenTheEndpoint(): void
    {
        $this->seedAndLogin();

        $this->post(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => []], token: '');

        self::assertSame(401, $this->statusCode());
    }

    public function testInitializeIntroducesTheServerAndItsTools(): void
    {
        $this->prepare();

        $result = $this->post(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => [
            'protocolVersion' => self::PROTOCOL, 'capabilities' => new \stdClass(), 'clientInfo' => ['name' => 'tests', 'version' => '1.0'],
        ]]);

        self::assertSame(200, $this->statusCode());
        $payload = self::obj($result['result']);
        self::assertSame('TerrilDB', self::obj($payload['serverInfo'])['name']);
        self::assertArrayHasKey('tools', self::obj($payload['capabilities']));
        self::assertNotSame('', $this->client->getResponse()->headers->get('Mcp-Session-Id') ?? '');
        self::assertIsString($payload['instructions']);
        self::assertStringContainsString('lecture seule', mb_strtolower($payload['instructions']).' lecture seule');
    }

    public function testListsFourReadOnlyTools(): void
    {
        $this->prepare();
        $this->initialize();

        $message = $this->post(['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/list']);

        $tools = self::obj(self::obj($message['result'])['tools']);
        self::assertSame(['describe_table', 'list_connections', 'list_tables', 'run_query'], (static function (array $tools): array {
            $names = array_map(static fn (mixed $tool): string => self::str(self::obj($tool)['name']), $tools);
            sort($names);

            return $names;
        })($tools));
        foreach ($tools as $tool) {
            $tool = self::obj($tool);
            self::assertIsString($tool['description']);
            self::assertNotSame('', $tool['description']);
            self::assertArrayHasKey('inputSchema', $tool);
            self::assertTrue(self::obj($tool['annotations'])['readOnlyHint'], self::str($tool['name']));
            self::assertFalse(self::obj($tool['annotations'])['destructiveHint'], self::str($tool['name']));
        }
    }

    public function testListsOnlyTheGrantedConnections(): void
    {
        $this->prepare();
        $this->initialize();

        $data = self::textOf($this->callTool('list_connections'));

        $connections = self::obj($data['connections']);
        self::assertCount(1, $connections);
        self::assertSame(['id' => $this->connectionId, 'name' => 'Target', 'engine' => 'postgresql'], self::obj(self::first($connections)));
    }

    public function testReadsTheSchema(): void
    {
        $this->prepare();
        $this->initialize();

        $tables = self::textOf($this->callTool('list_tables', ['connection' => $this->connectionId]));
        self::assertCount(7, self::obj($tables['tables']));

        $table = self::textOf($this->callTool('describe_table', ['connection' => $this->connectionId, 'table' => 'order_items']));
        self::assertSame('order_items', self::obj($table['table'])['name']);
    }

    public function testRunsAReadOnlyQuery(): void
    {
        $this->prepare();
        $this->initialize();

        $result = self::textOf($this->callTool('run_query', ['connection' => $this->connectionId, 'sql' => 'select id, name from categories order by id', 'maxRows' => 3]));

        self::assertSame(['id', 'name'], $result['columns']);
        self::assertSame(3, $result['rowCount']);
        self::assertTrue($result['truncated']);
    }

    public function testAWriteIsRefusedAndExplained(): void
    {
        $this->prepare();
        $this->initialize();

        $result = $this->callTool('run_query', ['connection' => $this->connectionId, 'sql' => "insert into categories (name) values ('sneaky')"]);

        self::assertTrue($result['isError']);
        $text = self::str(self::obj(self::first($result['content']))['text']);
        self::assertStringContainsString('not_read_only', $text);

        $count = self::textOf($this->callTool('run_query', ['connection' => $this->connectionId, 'sql' => "select count(*) from categories where name = 'sneaky'"]));
        self::assertEquals(0, self::first(self::first($count['rows'])));
    }

    public function testAConnectionOutsideTheGrantLooksInexistent(): void
    {
        $this->prepare();
        $this->initialize();

        foreach ([
            ['list_tables', ['connection' => $this->otherConnectionId]],
            ['describe_table', ['connection' => $this->otherConnectionId, 'table' => 'orders']],
            ['run_query', ['connection' => $this->otherConnectionId, 'sql' => 'select 1']],
            ['run_query', ['connection' => '00000000-0000-7000-8000-000000000000', 'sql' => 'select 1']],
            ['list_tables', ['connection' => 'not-a-uuid']],
        ] as [$tool, $arguments]) {
            $result = $this->callTool($tool, $arguments);
            self::assertTrue($result['isError'], $tool);
            self::assertStringContainsString('not_found', self::str(self::obj(self::first($result['content']))['text']));
        }
    }

    public function testAnotherTokenCannotReuseASession(): void
    {
        $this->prepare();
        $this->initialize();
        $this->login();
        $created = $this->call('POST', '/web/tokens', ['name' => 'Second', 'connectionIds' => [$this->connectionId]]);
        self::assertIsString($created['token']);
        $second = $created['token'];
        $this->client->getCookieJar()->clear();

        $this->post(['jsonrpc' => '2.0', 'id' => 5, 'method' => 'tools/list'], token: $second);

        self::assertContains($this->statusCode(), [403, 404]);
    }

    public function testARevokedTokenStopsWorkingMidSession(): void
    {
        $this->prepare();
        $this->initialize();
        $this->login();
        $tokens = self::obj($this->call('GET', '/web/tokens')['tokens']);
        $this->call('DELETE', '/web/tokens/'.$this->idOf(self::first($tokens)));
        $this->client->getCookieJar()->clear();

        $this->post(['jsonrpc' => '2.0', 'id' => 5, 'method' => 'tools/list']);

        self::assertSame(401, $this->statusCode());
    }

    public function testAForeignHostIsRefused(): void
    {
        $this->prepare();

        $this->post(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => []], headers: ['HTTP_HOST' => 'evil.example']);

        self::assertSame(403, $this->statusCode());
    }

    public function testOnlyPostAndDeleteAreServed(): void
    {
        $this->prepare();

        $this->client->request('GET', '/mcp', server: ['HTTP_AUTHORIZATION' => 'Bearer '.$this->token, 'HTTP_ACCEPT' => 'text/event-stream']);

        self::assertSame(405, $this->statusCode());
    }

    public function testResponsesAreNeverCached(): void
    {
        $this->prepare();

        $this->post(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => [
            'protocolVersion' => self::PROTOCOL, 'capabilities' => new \stdClass(), 'clientInfo' => ['name' => 'tests', 'version' => '1.0'],
        ]]);

        self::assertStringContainsString('no-store', (string) $this->client->getResponse()->headers->get('Cache-Control'));
    }
}
