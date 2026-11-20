<?php

declare(strict_types=1);

namespace App\Tests\Integration\Web;

use App\Tests\Integration\Driver\TargetDatabase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Le choix du tablet (`tablet=<alias>`) vaut aussi pour l'API à jetons et le MCP : sans lui un vtgate reste 501, avec lui
 * chaque thème se lit comme sur un MySQL ordinaire. Sauté si `target-vitess` est éteinte.
 */
#[Group('integration')]
final class VitessTabletApiTest extends WebApiTestCase
{
    private const PROTOCOL = '2025-06-18';

    private string $connectionId = '';
    private string $token = '';
    private string $session = '';

    private function prepare(): void
    {
        $this->seedAndLogin();
        try {
            $parameters = TargetDatabase::parameters('TARGET_VITESS_URL');
        } catch (\Throwable $e) {
            self::markTestSkipped($e->getMessage());
        }
        $created = $this->call('POST', '/web/connections', ['name' => 'Gateway', 'engine' => 'mysql', 'host' => $parameters->host, 'port' => $parameters->port, 'database' => $parameters->database, 'username' => $parameters->username, 'password' => $parameters->password()]);
        $id = self::arrayOf($created['connection'])['id'];
        self::assertIsString($id);
        $this->connectionId = $id;
        $token = $this->call('POST', '/web/tokens', ['name' => 'Assistant', 'connectionIds' => [$id], 'diagnostic' => true]);
        self::assertIsString($token['token']);
        $this->token = $token['token'];
        $this->client->getCookieJar()->clear();
    }

    /**
     * @return array<string, mixed>
     */
    private function api(string $uri): array
    {
        return $this->call('GET', $uri, csrf: false, extraServer: ['HTTP_AUTHORIZATION' => 'Bearer '.$this->token]);
    }

    private function replicaAlias(): string
    {
        $body = $this->api('/api/v1/connections/'.$this->connectionId.'/diagnostics/tablets');
        if (502 === $this->statusCode()) {
            self::markTestSkipped('TARGET_VITESS_URL is unreachable (docker compose --profile vitess up -d --wait target-vitess).');
        }
        self::assertSame(200, $this->statusCode());
        foreach (self::listOf($body['tablets']) as $tablet) {
            $tablet = self::arrayOf($tablet);
            if ('REPLICA' === $tablet['type']) {
                self::assertIsString($tablet['alias']);

                return $tablet['alias'];
            }
        }
        self::fail('target-vitess has a replica tablet');
    }

    public function testTheApiListsTheTabletsAndReadsThemeFromTheChosenOne(): void
    {
        $this->prepare();
        $alias = $this->replicaAlias();
        $base = '/api/v1/connections/'.$this->connectionId.'/diagnostics';

        $this->api($base.'/sessions');
        self::assertSame(501, $this->statusCode());

        $sessions = $this->api($base.'/sessions?tablet='.$alias);
        self::assertSame(200, $this->statusCode());
        self::assertSame('available', self::arrayOf($sessions['status'])['availability']);

        $capabilities = $this->api($base.'?tablet='.$alias);
        self::assertSame(200, $this->statusCode());
        self::assertArrayHasKey('capabilities', $capabilities);
    }

    public function testTheApiRefusesAnUnknownTablet(): void
    {
        $this->prepare();
        $this->replicaAlias();

        $this->api('/api/v1/connections/'.$this->connectionId.'/diagnostics/sessions?tablet=nowhere-0000000999');

        self::assertSame(422, $this->statusCode());
    }

    public function testTheTabletsRouteNeedsTheDiagnosticRight(): void
    {
        $this->seedAndLogin();
        try {
            $parameters = TargetDatabase::parameters('TARGET_VITESS_URL');
        } catch (\Throwable $e) {
            self::markTestSkipped($e->getMessage());
        }
        $created = $this->call('POST', '/web/connections', ['name' => 'Gateway', 'engine' => 'mysql', 'host' => $parameters->host, 'port' => $parameters->port, 'database' => $parameters->database, 'username' => $parameters->username, 'password' => $parameters->password()]);
        $id = self::arrayOf($created['connection'])['id'];
        self::assertIsString($id);
        $token = $this->call('POST', '/web/tokens', ['name' => 'Plain', 'connectionIds' => [$id]]);
        self::assertIsString($token['token']);
        $this->client->getCookieJar()->clear();

        $this->call('GET', '/api/v1/connections/'.$id.'/diagnostics/tablets', csrf: false, extraServer: ['HTTP_AUTHORIZATION' => 'Bearer '.$token['token']]);

        self::assertSame(403, $this->statusCode());
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array<mixed>
     */
    private function post(array $payload): array
    {
        $headers = ['HTTP_AUTHORIZATION' => 'Bearer '.$this->token, 'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json, text/event-stream', 'HTTP_MCP_PROTOCOL_VERSION' => self::PROTOCOL];
        if ('' !== $this->session) {
            $headers['HTTP_MCP_SESSION_ID'] = $this->session;
        }
        $this->client->request('POST', '/mcp', server: $headers, content: json_encode($payload, \JSON_THROW_ON_ERROR));
        $decoded = json_decode($this->client->getResponse()->getContent() ?: '{}', true);

        return \is_array($decoded) ? $decoded : [];
    }

    /**
     * @param array<string, mixed> $arguments
     *
     * @return array<mixed>
     */
    private function tool(string $name, array $arguments): array
    {
        $message = $this->post(['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/call', 'params' => ['name' => $name, 'arguments' => $arguments]]);

        return self::arrayOf($message['result'] ?? $message);
    }

    /**
     * @param array<mixed> $result
     *
     * @return array<mixed>
     */
    private static function textOf(array $result): array
    {
        $content = self::arrayOf(self::listOf($result['content'])[0]);
        self::assertIsString($content['text']);

        return self::arrayOf(json_decode($content['text'], true, flags: \JSON_THROW_ON_ERROR));
    }

    public function testMcpListsTheTabletsAndReadsThemeFromTheChosenOne(): void
    {
        $this->prepare();
        $this->replicaAlias();
        $this->post(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => ['protocolVersion' => self::PROTOCOL, 'capabilities' => new \stdClass(), 'clientInfo' => ['name' => 'tests', 'version' => '1.0']]]);
        $this->session = $this->client->getResponse()->headers->get('Mcp-Session-Id') ?? '';
        $this->post(['jsonrpc' => '2.0', 'method' => 'notifications/initialized']);

        $tablets = self::textOf($this->tool('list_tablets', ['connection' => $this->connectionId]));
        $alias = null;
        foreach (self::listOf($tablets['tablets']) as $tablet) {
            $tablet = self::arrayOf($tablet);
            if ('REPLICA' === $tablet['type']) {
                $alias = $tablet['alias'];
            }
        }
        self::assertIsString($alias);

        $refused = $this->tool('list_sessions', ['connection' => $this->connectionId]);
        self::assertTrue($refused['isError']);

        $sessions = self::textOf($this->tool('list_sessions', ['connection' => $this->connectionId, 'tablet' => $alias]));
        self::assertSame('available', self::arrayOf($sessions['status'])['availability']);
    }
}
