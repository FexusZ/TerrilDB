<?php

declare(strict_types=1);

namespace App\Tests\Integration\Web;

use App\Audit\AuditActor;
use App\Tests\Integration\Driver\TargetDatabase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Un script détaché par l'API à jetons (#169) : même ticket de confirmation que l'exécution synchrone, 202 + un identifiant, suivi et
 * arrêt réservés au jeton qui l'a lancé ; le propriétaire du compte la voit sur le web.
 */
#[Group('integration')]
final class ScriptRunApiTest extends WebApiTestCase
{
    private string $connectionId = '';
    private string $token = '';
    private string $otherToken = '';

    protected function setUp(): void
    {
        parent::setUp();
        RecordingScriptRunLauncher::reset();
    }

    /**
     * @param array<string, bool> $rights
     */
    private function setUpToken(array $rights = ['writeData' => true]): void
    {
        $this->seedAndLogin();
        $parameters = TargetDatabase::parameters('TARGET_POSTGRES_URL');
        $created = $this->call('POST', '/web/connections', ['name' => 'Target', 'engine' => 'postgresql', 'host' => $parameters->host, 'port' => $parameters->port, 'database' => $parameters->database, 'username' => $parameters->username, 'password' => $parameters->password()]);
        $id = self::arrayOf($created['connection'])['id'];
        self::assertIsString($id);
        $this->connectionId = $id;
        $first = $this->call('POST', '/web/tokens', ['name' => 'Assistant', 'connectionIds' => [$id]] + $rights);
        $second = $this->call('POST', '/web/tokens', ['name' => 'Autre', 'connectionIds' => [$id]] + $rights);
        self::assertIsString($first['token']);
        self::assertIsString($second['token']);
        $this->token = $first['token'];
        $this->otherToken = $second['token'];
    }

    /**
     * @param array<string, mixed> $body
     *
     * @return array<string, mixed>
     */
    private function api(string $method, string $path, array $body = [], ?string $token = null): array
    {
        $this->client->getCookieJar()->clear();

        return $this->call($method, '/api/v1/'.$path, $body, csrf: false, extraServer: ['HTTP_AUTHORIZATION' => 'Bearer '.($token ?? $this->token)]);
    }

    private function ticket(string $script): string
    {
        $prepared = $this->api('POST', 'connections/'.$this->connectionId.'/script/prepare', ['script' => $script]);
        self::assertSame(200, $this->statusCode());
        self::assertIsString($prepared['confirmationToken']);

        return $prepared['confirmationToken'];
    }

    /**
     * @return array<string, mixed>
     */
    private function launch(string $script = 'SELECT 1; SELECT 2'): array
    {
        $body = $this->api('POST', 'connections/'.$this->connectionId.'/script/run', ['script' => $script, 'confirmationToken' => $this->ticket($script)]);
        self::assertSame(202, $this->statusCode());

        $run = self::arrayOf($body['run']);
        /** @var array<string, mixed> $run */

        return $run;
    }

    public function testWithoutATicketNothingIsLaunched(): void
    {
        $this->setUpToken();

        $body = $this->api('POST', 'connections/'.$this->connectionId.'/script/run', ['script' => 'SELECT 1']);

        self::assertSame(409, $this->statusCode());
        self::assertSame(['error' => 'preparation_required'], $body);
        self::assertSame([], RecordingScriptRunLauncher::$requests);
    }

    public function testATokenWithoutAWriteRightCannotLaunchOrFollow(): void
    {
        $this->setUpToken(['readSchema' => true]);

        $this->api('POST', 'connections/'.$this->connectionId.'/script/run', ['script' => 'SELECT 1', 'confirmationToken' => 'cft_x']);
        self::assertSame(403, $this->statusCode());
        $this->api('GET', 'script-runs/00000000-0000-7000-8000-000000000000');
        self::assertSame(403, $this->statusCode());
        self::assertSame([], RecordingScriptRunLauncher::$requests);
    }

    public function testATicketLaunchesTheRunWithTheTokenRightsAndIsConsumed(): void
    {
        $this->setUpToken();
        $script = 'SELECT 1; SELECT 2';
        $ticket = $this->ticket($script);

        $body = $this->api('POST', 'connections/'.$this->connectionId.'/script/run', ['script' => $script, 'confirmationToken' => $ticket]);

        self::assertSame(202, $this->statusCode());
        $run = self::arrayOf($body['run']);
        self::assertSame(['queued', 2, $this->connectionId], [$run['status'], $run['total'], $run['connectionId']]);
        $sent = RecordingScriptRunLauncher::last();
        self::assertSame(AuditActor::Api, $sent->audit->actor);
        self::assertNotNull($sent->audit->tokenId);
        self::assertTrue($sent->writes->data);
        self::assertFalse($sent->writes->schema);
        self::assertFalse($sent->lenient);

        $this->api('POST', 'connections/'.$this->connectionId.'/script/run', ['script' => $script, 'confirmationToken' => $ticket]);
        self::assertSame(403, $this->statusCode());
        self::assertCount(1, RecordingScriptRunLauncher::$requests);
    }

    public function testOnlyTheLaunchingTokenFollowsAndStopsTheRun(): void
    {
        $this->setUpToken();
        $run = $this->launch();
        $id = $run['id'];
        self::assertIsString($id);

        $seen = $this->api('GET', 'script-runs/'.$id);
        self::assertSame(200, $this->statusCode());
        self::assertSame($id, self::arrayOf($seen['run'])['id']);

        $this->api('GET', 'script-runs/'.$id, token: $this->otherToken);
        self::assertSame(404, $this->statusCode());
        $this->api('POST', 'script-runs/'.$id.'/cancel', token: $this->otherToken);
        self::assertSame(404, $this->statusCode());

        $cancelled = $this->api('POST', 'script-runs/'.$id.'/cancel');
        self::assertSame(200, $this->statusCode());
        self::assertTrue(self::arrayOf($cancelled['run'])['cancelRequested']);
    }

    public function testTheOwnerSeesATokenRunOnTheWebAndCanStopIt(): void
    {
        $this->setUpToken();
        $id = $this->launch()['id'];
        self::assertIsString($id);

        $this->client->getCookieJar()->clear();
        $this->login('alice');
        $list = $this->call('GET', '/web/script-runs');
        self::assertSame($id, self::arrayOf(self::arrayOf($list['runs'])[0])['id']);
        $this->call('POST', '/web/script-runs/'.$id.'/cancel');
        self::assertSame(200, $this->statusCode());
    }

    public function testATimeoutOutOfBoundsIsRefusedBeforeTheTicketIsBurnt(): void
    {
        $this->setUpToken();
        $script = 'SELECT 1';
        $ticket = $this->ticket($script);

        $this->api('POST', 'connections/'.$this->connectionId.'/script/run', ['script' => $script, 'confirmationToken' => $ticket, 'timeoutMs' => 1]);
        self::assertSame(422, $this->statusCode());

        $this->api('POST', 'connections/'.$this->connectionId.'/script/run', ['script' => $script, 'confirmationToken' => $ticket]);
        self::assertSame(202, $this->statusCode());
    }
}
