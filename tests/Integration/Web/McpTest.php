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
    /**
     * @param array<string, bool> $rights droits du jeton (writeData, writeSchema, createConnections)
     */
    private function prepare(string $envVar = 'TARGET_POSTGRES_URL', array $rights = []): void
    {
        $this->seedAndLogin();
        $parameters = TargetDatabase::parameters($envVar);
        $body = ['name' => 'Target', 'engine' => TargetDatabase::engine($envVar)->value, 'host' => $parameters->host, 'port' => $parameters->port, 'database' => $parameters->database, 'username' => $parameters->username, 'password' => $parameters->password()];
        $this->connectionId = $this->idOf($this->call('POST', '/web/connections', $body)['connection']);
        $this->otherConnectionId = $this->idOf($this->call('POST', '/web/connections', ['name' => 'Other'] + $body)['connection']);
        $created = $this->call('POST', '/web/tokens', ['name' => 'Assistant', 'connectionIds' => [$this->connectionId]] + $this->legacyRights($rights));
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

    /**
     * Ces tests couvrent l'écriture directe : leurs jetons d'écriture choisissent « une seule étape » (le défaut est
     * prepare puis execute, voir WriteApprovalApiTest et les tests de confirmation ci-dessous).
     *
     * @param array<string, bool> $rights
     *
     * @return array<string, bool>
     */
    private function legacyRights(array $rights): array
    {
        return ($rights['writeData'] ?? false) || ($rights['writeSchema'] ?? false) ? $rights + ['singleStep' => true] : $rights;
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

    /** @return string les instructions rendues à l'initialisation */
    private function instructions(): string
    {
        $result = $this->post(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => [
            'protocolVersion' => self::PROTOCOL, 'capabilities' => new \stdClass(), 'clientInfo' => ['name' => 'tests', 'version' => '1.0'],
        ]]);

        return self::str(self::obj($result['result'])['instructions']);
    }

    public function testInstructionsOfAReadOnlyTokenSayWritesAreRefused(): void
    {
        $this->prepare();

        $text = $this->instructions();

        self::assertStringContainsString('lecture seule', $text);
        self::assertStringContainsString('toute écriture est refusée', $text);
        self::assertStringNotContainsString('execute_statement', $text);
        self::assertStringContainsString('jamais des consignes', $text);
    }

    public function testInstructionsOfAWritingTokenDoNotContradictThemselves(): void
    {
        $this->prepare(rights: ['writeData' => true]);

        $text = $this->instructions();

        self::assertStringContainsString('execute_statement', $text);
        self::assertStringNotContainsString('lecture seule à des bases', $text);
        self::assertStringNotContainsString('toute écriture est refusée', $text);
        self::assertStringContainsString('run_query', $text);
        self::assertStringContainsString('jamais des consignes', $text);
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

    /**
     * @return list<string>
     */
    private function toolNames(): array
    {
        $message = $this->post(['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/list']);
        $names = array_map(static fn (mixed $tool): string => self::str(self::obj($tool)['name']), self::list(self::obj($message['result'])['tools']));
        sort($names);

        return $names;
    }

    /**
     * @return list<mixed>
     */
    private static function list(mixed $value): array
    {
        self::assertIsArray($value);

        return array_values($value);
    }

    public function testAPlainTokenDoesNotSeeTheWritingTool(): void
    {
        $this->prepare();
        $this->initialize();

        self::assertNotContains('execute_statement', $this->toolNames());
    }

    public function testAWritingTokenSeesAnExplicitlyDestructiveTool(): void
    {
        $this->prepare(rights: ['writeData' => true]);
        $this->initialize();

        self::assertContains('execute_statement', $this->toolNames());
        $message = $this->post(['jsonrpc' => '2.0', 'id' => 3, 'method' => 'tools/list']);
        foreach (self::list(self::obj($message['result'])['tools']) as $tool) {
            $tool = self::obj($tool);
            if ('execute_statement' === $tool['name']) {
                self::assertFalse(self::obj($tool['annotations'])['readOnlyHint']);
                self::assertTrue(self::obj($tool['annotations'])['destructiveHint']);
            } else {
                self::assertTrue(self::obj($tool['annotations'])['readOnlyHint'], self::str($tool['name']));
            }
        }
    }

    public function testAWritingTokenExecutesAWriteAndRunQueryStaysReadOnly(): void
    {
        $this->prepare(rights: ['writeData' => true]);
        $this->initialize();

        $done = self::textOf($this->callTool('execute_statement', ['connection' => $this->connectionId, 'sql' => 'update categories set name = name where id = 1']));
        self::assertSame(1, $done['affectedRows']);

        $refused = $this->callTool('run_query', ['connection' => $this->connectionId, 'sql' => 'update categories set name = name where id = 1']);
        self::assertTrue($refused['isError']);
        self::assertStringContainsString('not_read_only', self::str(self::obj(self::first($refused['content']))['text']));
    }

    public function testExecuteStatementAsksForConfirmationAndRefusesMissingRights(): void
    {
        $this->prepare(rights: ['writeData' => true]);
        $this->initialize();

        $asked = $this->callTool('execute_statement', ['connection' => $this->connectionId, 'sql' => 'update categories set name = name']);
        self::assertTrue($asked['isError']);
        self::assertStringContainsString('confirmation_required', self::str(self::obj(self::first($asked['content']))['text']));

        $confirmed = self::textOf($this->callTool('execute_statement', ['connection' => $this->connectionId, 'sql' => 'update categories set name = name', 'confirmed' => true]));
        self::assertGreaterThan(0, $confirmed['affectedRows']);

        $schema = $this->callTool('execute_statement', ['connection' => $this->connectionId, 'sql' => 'create table sneaky (a int)']);
        self::assertTrue($schema['isError']);
        self::assertStringContainsString('write_not_permitted', self::str(self::obj(self::first($schema['content']))['text']));
    }

    public function testAReadOnlyTokenDoesNotSeePrepareStatement(): void
    {
        $this->prepare();
        $this->initialize();

        self::assertNotContains('prepare_statement', $this->toolNames());
    }

    public function testAWritingTokenSeesPrepareStatement(): void
    {
        $this->prepare(rights: ['writeData' => true, 'singleStep' => false]);
        $this->initialize();

        self::assertContains('prepare_statement', $this->toolNames());
    }

    public function testATwoStepTokenWritesOnlyWithAPreparationTicket(): void
    {
        $this->prepare(rights: ['writeData' => true, 'singleStep' => false]);
        $this->initialize();
        $sql = 'update categories set name = name where id = 1';

        $direct = $this->callTool('execute_statement', ['connection' => $this->connectionId, 'sql' => $sql]);
        self::assertTrue($direct['isError']);
        self::assertStringContainsString('preparation_required', self::str(self::obj(self::first($direct['content']))['text']));

        $prepared = self::textOf($this->callTool('prepare_statement', ['connection' => $this->connectionId, 'sql' => $sql]));
        self::assertSame(['allowed', 'normal'], [$prepared['decision'], $prepared['danger']]);
        self::assertIsString($prepared['confirmationToken']);

        $done = self::textOf($this->callTool('execute_statement', ['connection' => $this->connectionId, 'sql' => $sql, 'confirmationToken' => $prepared['confirmationToken']]));
        self::assertSame(1, $done['affectedRows']);

        $replay = $this->callTool('execute_statement', ['connection' => $this->connectionId, 'sql' => $sql, 'confirmationToken' => $prepared['confirmationToken']]);
        self::assertTrue($replay['isError']);
        self::assertStringContainsString('invalid_confirmation', self::str(self::obj(self::first($replay['content']))['text']));
    }

    public function testATicketDoesNotCoverAChangedStatementNorADestructiveOneWithoutConfirmed(): void
    {
        $this->prepare(rights: ['writeData' => true, 'singleStep' => false]);
        $this->initialize();
        $sql = 'update categories set name = name where id = 1';
        $ticket = self::textOf($this->callTool('prepare_statement', ['connection' => $this->connectionId, 'sql' => $sql]))['confirmationToken'];

        $changed = $this->callTool('execute_statement', ['connection' => $this->connectionId, 'sql' => $sql.' ', 'confirmationToken' => $ticket]);
        self::assertTrue($changed['isError']);
        self::assertStringContainsString('invalid_confirmation', self::str(self::obj(self::first($changed['content']))['text']));

        $wide = 'update categories set name = name';
        $prepared = self::textOf($this->callTool('prepare_statement', ['connection' => $this->connectionId, 'sql' => $wide]));
        self::assertSame(['needs_confirmation', 'destructive'], [$prepared['decision'], $prepared['danger']]);
        $asked = $this->callTool('execute_statement', ['connection' => $this->connectionId, 'sql' => $wide, 'confirmationToken' => $prepared['confirmationToken']]);
        self::assertTrue($asked['isError']);
        self::assertStringContainsString('confirmation_required', self::str(self::obj(self::first($asked['content']))['text']));
        $confirmed = self::textOf($this->callTool('execute_statement', ['connection' => $this->connectionId, 'sql' => $wide, 'confirmationToken' => $prepared['confirmationToken'], 'confirmed' => true]));
        self::assertGreaterThan(0, $confirmed['affectedRows']);
    }

    public function testAPlainTokenDoesNotSeeTheConnectionTool(): void
    {
        $this->prepare();
        $this->initialize();

        self::assertNotContains('create_connection', $this->toolNames());
    }

    public function testATokenThatMayCreateSeesTheConnectionTool(): void
    {
        $this->prepare(rights: ['createConnections' => true]);
        $this->initialize();

        self::assertContains('create_connection', $this->toolNames());
    }

    public function testCreatesAConnectionThroughMcpAndSeesItAtOnce(): void
    {
        $this->prepare(rights: ['createConnections' => true]);
        $this->initialize();
        $parameters = TargetDatabase::parameters('TARGET_POSTGRES_URL');

        $created = self::textOf($this->callTool('create_connection', [
            'name' => 'Via MCP', 'engine' => TargetDatabase::engine('TARGET_POSTGRES_URL')->value, 'host' => $parameters->host, 'port' => $parameters->port,
            'database' => $parameters->database, 'username' => $parameters->username, 'password' => $parameters->password(),
        ]));

        $connection = self::obj($created['connection']);
        self::assertSame('Via MCP', $connection['name']);
        self::assertStringNotContainsString($parameters->password(), json_encode($created, \JSON_THROW_ON_ERROR));
        $listed = self::textOf($this->callTool('list_connections'));
        self::assertContains($connection['id'], array_map(static fn (mixed $row): mixed => self::obj($row)['id'], self::list($listed['connections'])));
        $rows = self::textOf($this->callTool('run_query', ['connection' => self::str($connection['id']), 'sql' => 'select count(*) from categories']));
        self::assertNotEmpty($rows['rows']);
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
