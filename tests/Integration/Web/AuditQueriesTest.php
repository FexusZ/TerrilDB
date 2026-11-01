<?php

declare(strict_types=1);

namespace App\Tests\Integration\Web;

use App\Audit\AuditAction;
use App\Audit\AuditActor;
use App\Audit\AuditOutcome;
use App\Entity\User;
use App\Tests\Integration\Driver\TargetDatabase;
use PHPUnit\Framework\Attributes\Group;

#[Group('integration')]
final class AuditQueriesTest extends WebApiTestCase
{
    private User $user;
    private string $connectionId = '';

    private function prepare(): void
    {
        $this->user = $this->seedAndLogin();
        $parameters = TargetDatabase::parameters('TARGET_POSTGRES_URL');
        $created = $this->call('POST', '/web/connections', ['name' => 'Target', 'engine' => 'postgresql', 'host' => $parameters->host, 'port' => $parameters->port, 'database' => $parameters->database, 'username' => $parameters->username, 'password' => $parameters->password()]);
        $id = self::arrayOf($created['connection'])['id'];
        self::assertIsString($id);
        $this->connectionId = $id;
    }

    /**
     * @param array<string, mixed> $extra
     */
    private function webQuery(string $sql, array $extra = []): void
    {
        $this->call('POST', '/web/connections/'.$this->connectionId.'/query', ['sql' => $sql] + $extra);
    }

    public function testASuccessfulQueryLeavesATrace(): void
    {
        $this->prepare();

        $this->webQuery('select id from categories order by id', ['maxRows' => 2]);

        $event = $this->auditEvents($this->user, 'query')[0];
        self::assertSame(AuditAction::QueryExecuted, $event->getAction());
        self::assertSame(AuditOutcome::Success, $event->getOutcome());
        self::assertSame(AuditActor::Web, $event->getActor());
        self::assertSame($this->connectionId, $event->getSubjectId()?->toRfc4122());
        self::assertNull($event->getTokenId());
        $details = $event->getDetails();
        self::assertSame('select id from categories order by id', $details['sql']);
        self::assertSame('read_only', $details['mode']);
        self::assertSame(2, $details['rowCount']);
        self::assertTrue($details['truncated']);
        self::assertSame('postgresql', $details['engine']);
        self::assertIsInt($details['durationMs']);
    }

    public function testARefusedQueryIsRecordedWithItsReason(): void
    {
        $this->prepare();

        $this->webQuery("delete from categories where name = 'x'");

        $event = $this->auditEvents($this->user, 'query')[0];
        self::assertSame(AuditAction::QueryRefused, $event->getAction());
        self::assertSame(AuditOutcome::Refused, $event->getOutcome());
        self::assertSame('not_read_only', $event->getDetails()['reason']);
        self::assertSame("delete from categories where name = 'x'", $event->getDetails()['sql']);
    }

    public function testAnEngineErrorIsRecorded(): void
    {
        $this->prepare();

        $this->webQuery('select * from no_such_table');

        $event = $this->auditEvents($this->user, 'query')[0];
        self::assertSame(AuditAction::QueryFailed, $event->getAction());
        self::assertSame(AuditOutcome::Failed, $event->getOutcome());
        self::assertIsString($event->getDetails()['error']);
        self::assertStringContainsString('no_such_table', $event->getDetails()['error']);
    }

    public function testATimeoutIsRecordedAsSuch(): void
    {
        $this->prepare();

        $this->webQuery('select pg_sleep(5)', ['timeoutMs' => 300]);

        $event = $this->auditEvents($this->user, 'query')[0];
        self::assertSame(AuditAction::QueryFailed, $event->getAction());
        self::assertSame('timeout', $event->getDetails()['reason']);
    }

    public function testAConfirmationRequestIsNotAnExecution(): void
    {
        $this->prepare();

        $this->webQuery('delete from categories', ['mode' => 'read_write']);
        self::assertSame(409, $this->statusCode());

        self::assertSame([], $this->auditEvents($this->user, 'query'));
    }

    public function testEveryQueryOfASeriesIsKept(): void
    {
        $this->prepare();

        $this->webQuery('select 1');
        $this->webQuery('select 2');
        $this->webQuery('drop table categories');

        self::assertSame(
            ['query.refused', 'query.executed', 'query.executed'],
            array_map(static fn ($event): string => $event->getAction()->value, $this->auditEvents($this->user, 'query')),
        );
    }

    public function testAnApiQueryNamesTheTokenAndTheDoor(): void
    {
        $this->prepare();
        $created = $this->call('POST', '/web/tokens', ['name' => 'Assistant', 'connectionIds' => [$this->connectionId]]);
        $token = $created['token'];
        self::assertIsString($token);
        $tokenId = self::arrayOf($created['tokenInfo'])['id'];
        $this->client->getCookieJar()->clear();

        $this->call('POST', '/api/v1/connections/'.$this->connectionId.'/query', ['sql' => 'select 1'], csrf: false, extraServer: ['HTTP_AUTHORIZATION' => 'Bearer '.$token]);
        self::assertSame(200, $this->statusCode());

        $event = $this->auditEvents($this->user, 'query')[0];
        self::assertSame(AuditActor::Api, $event->getActor());
        self::assertSame($tokenId, $event->getTokenId()?->toRfc4122());
        self::assertSame('select 1', $event->getDetails()['sql']);
    }

    public function testAnMcpQueryIsRecordedAsMcp(): void
    {
        $this->prepare();
        $created = $this->call('POST', '/web/tokens', ['name' => 'Assistant', 'connectionIds' => [$this->connectionId]]);
        $token = $created['token'];
        self::assertIsString($token);
        $this->client->getCookieJar()->clear();
        $headers = ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json, text/event-stream', 'HTTP_MCP_PROTOCOL_VERSION' => '2025-06-18', 'HTTP_AUTHORIZATION' => 'Bearer '.$token];

        $this->client->request('POST', '/mcp', server: $headers, content: json_encode(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => ['protocolVersion' => '2025-06-18', 'capabilities' => new \stdClass(), 'clientInfo' => ['name' => 't', 'version' => '1']]], \JSON_THROW_ON_ERROR));
        $headers['HTTP_MCP_SESSION_ID'] = (string) $this->client->getResponse()->headers->get('Mcp-Session-Id');
        $this->client->request('POST', '/mcp', server: $headers, content: json_encode(['jsonrpc' => '2.0', 'method' => 'notifications/initialized'], \JSON_THROW_ON_ERROR));
        $this->client->request('POST', '/mcp', server: $headers, content: json_encode(['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/call', 'params' => ['name' => 'run_query', 'arguments' => ['connection' => $this->connectionId, 'sql' => 'select 1']]], \JSON_THROW_ON_ERROR));

        $event = $this->auditEvents($this->user, 'query')[0];
        self::assertSame(AuditActor::Mcp, $event->getActor());
        self::assertNotNull($event->getTokenId());
    }
}
