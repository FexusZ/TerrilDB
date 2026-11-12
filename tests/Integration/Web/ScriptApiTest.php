<?php

declare(strict_types=1);

namespace App\Tests\Integration\Web;

use App\Core\Connection\Engine;
use App\Driver\DbalConnector;
use App\Entity\User;
use App\Tests\Integration\Driver\TargetDatabase;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\Group;

/**
 * Un script par l'API à jetons : toujours en deux temps (même pour un jeton « une seule étape »), chaque instruction jugée
 * avec les droits du jeton, un seul jeton de confirmation pour l'ensemble.
 */
#[Group('integration')]
final class ScriptApiTest extends WebApiTestCase
{
    private ?Connection $target = null;
    private string $table = '';
    private string $connectionId = '';
    private string $token = '';
    private ?User $owner = null;

    protected function tearDown(): void
    {
        if ('' !== $this->table) {
            $this->target()->executeStatement('DROP TABLE IF EXISTS '.$this->table);
        }
        $this->target?->close();
        parent::tearDown();
    }

    private function target(): Connection
    {
        return $this->target ??= (new DbalConnector())->connect(Engine::PostgreSql, TargetDatabase::parameters('TARGET_POSTGRES_URL'));
    }

    /**
     * @param array<string, bool> $rights
     */
    private function setUpToken(array $rights = ['writeData' => true]): void
    {
        $this->table = 'tdb_sa_'.bin2hex(random_bytes(4));
        $this->target()->executeStatement(\sprintf('CREATE TABLE %s (id integer PRIMARY KEY, v varchar(20))', $this->table));
        $this->owner = $this->seedAndLogin();
        $parameters = TargetDatabase::parameters('TARGET_POSTGRES_URL');
        $created = $this->call('POST', '/web/connections', ['name' => 'Target', 'engine' => 'postgresql', 'host' => $parameters->host, 'port' => $parameters->port, 'database' => $parameters->database, 'username' => $parameters->username, 'password' => $parameters->password()]);
        $id = self::arrayOf($created['connection'])['id'];
        self::assertIsString($id);
        $this->connectionId = $id;
        $token = $this->call('POST', '/web/tokens', ['name' => 'Assistant', 'connectionIds' => [$id]] + $rights);
        self::assertIsString($token['token']);
        $this->token = $token['token'];
        $this->client->getCookieJar()->clear();
    }

    /**
     * @param array<string, mixed> $body
     *
     * @return array<string, mixed>
     */
    private function api(string $path, array $body): array
    {
        return $this->call('POST', '/api/v1/connections/'.$this->connectionId.'/'.$path, $body, csrf: false, extraServer: ['HTTP_AUTHORIZATION' => 'Bearer '.$this->token]);
    }

    private function rows(): int
    {
        $count = $this->target()->fetchOne('SELECT count(*) FROM '.$this->table);

        return is_numeric($count) ? (int) $count : -1;
    }

    private function script(): string
    {
        return \sprintf("INSERT INTO %1\$s VALUES (1, 'a'); INSERT INTO %1\$s VALUES (2, 'b');", $this->table);
    }

    private function ticket(string $script): string
    {
        $prepared = $this->api('script/prepare', ['script' => $script]);
        self::assertSame(200, $this->statusCode());
        self::assertIsString($prepared['confirmationToken']);

        return $prepared['confirmationToken'];
    }

    public function testWithoutATicketNothingRuns(): void
    {
        $this->setUpToken();

        $body = $this->api('script/execute', ['script' => $this->script()]);

        self::assertSame(409, $this->statusCode());
        self::assertSame(['error' => 'preparation_required'], $body);
        self::assertSame(0, $this->rows());
    }

    public function testASingleStepTokenStillNeedsATicketForAScript(): void
    {
        $this->setUpToken(['writeData' => true, 'singleStep' => true]);

        $this->api('script/execute', ['script' => $this->script()]);

        self::assertSame(409, $this->statusCode());
        self::assertSame(0, $this->rows());
    }

    public function testPrepareJudgesEveryStatementAndRunsNothing(): void
    {
        $this->setUpToken();

        $body = $this->api('script/prepare', ['script' => $this->script()]);

        self::assertSame(200, $this->statusCode());
        self::assertSame(['allowed', 'normal', 2], [$body['decision'], $body['danger'], \count(self::arrayOf($body['steps']))]);
        self::assertTrue($body['canRun']);
        self::assertSame(['available' => true, 'reason' => null], $body['dryRun']);
        self::assertIsString($body['confirmationToken']);
        self::assertSame(0, $this->rows());
    }

    public function testTheTicketRunsTheExactScriptOnceInOneTransaction(): void
    {
        $this->setUpToken();
        $script = $this->script();
        $ticket = $this->ticket($script);

        $done = $this->api('script/execute', ['script' => $script, 'confirmationToken' => $ticket]);

        self::assertSame(200, $this->statusCode());
        self::assertSame('committed', $done['status']);
        self::assertSame([0, 1], $done['applied']);
        self::assertSame(2, $this->rows());

        $this->target()->executeStatement('DELETE FROM '.$this->table);
        $this->api('script/execute', ['script' => $script, 'confirmationToken' => $ticket]);
        self::assertSame(403, $this->statusCode());
        self::assertSame(0, $this->rows());
    }

    public function testAScriptChangedByOneByteIsRefused(): void
    {
        $this->setUpToken();
        $ticket = $this->ticket($this->script());

        $this->api('script/execute', ['script' => $this->script().' ', 'confirmationToken' => $ticket]);

        self::assertSame(403, $this->statusCode());
        self::assertSame(0, $this->rows());
    }

    public function testAStatementTicketCannotRunAScript(): void
    {
        $this->setUpToken();
        $sql = \sprintf("INSERT INTO %s VALUES (1, 'a')", $this->table);
        $prepared = $this->api('prepare', ['sql' => $sql]);
        self::assertIsString($prepared['confirmationToken']);

        $this->api('script/execute', ['script' => $sql, 'confirmationToken' => $prepared['confirmationToken']]);

        self::assertSame(403, $this->statusCode());
        self::assertSame(0, $this->rows());
    }

    public function testEveryStatementIsJudgedWithTheRightsOfTheToken(): void
    {
        $this->setUpToken(['writeData' => true]);

        $body = $this->api('script/prepare', ['script' => \sprintf("INSERT INTO %s VALUES (1, 'a'); CREATE TABLE sneaky (a int)", $this->table)]);

        self::assertSame(403, $this->statusCode());
        self::assertSame(['error' => 'script_refused', 'reason' => 'write_not_permitted', 'statement' => 1], $body);
    }

    public function testADestructiveScriptNeedsTheTicketAndOneConfirmed(): void
    {
        $this->setUpToken();
        $script = \sprintf("INSERT INTO %1\$s VALUES (1, 'a'); DELETE FROM %1\$s;", $this->table);
        $prepared = $this->api('script/prepare', ['script' => $script]);
        self::assertSame(['needs_confirmation', 'destructive'], [$prepared['decision'], $prepared['danger']]);
        $ticket = $prepared['confirmationToken'];
        self::assertIsString($ticket);

        $asked = $this->api('script/execute', ['script' => $script, 'confirmationToken' => $ticket]);
        self::assertSame(409, $this->statusCode());
        self::assertSame('confirmation_required', $asked['error']);

        $done = $this->api('script/execute', ['script' => $script, 'confirmationToken' => $ticket, 'confirmed' => true]);
        self::assertSame(200, $this->statusCode());
        self::assertSame('committed', $done['status']);
    }

    public function testADryRunNeedsATicketAndCancelsEverything(): void
    {
        $this->setUpToken();
        $script = $this->script();

        $done = $this->api('script/execute', ['script' => $script, 'confirmationToken' => $this->ticket($script), 'dryRun' => true]);

        self::assertSame(200, $this->statusCode());
        self::assertSame('dry_run', $done['status']);
        self::assertSame(0, $this->rows());
    }

    public function testAReadOnlyTokenCannotPrepareAScript(): void
    {
        $this->setUpToken([]);

        $body = $this->api('script/prepare', ['script' => $this->script()]);

        self::assertSame(403, $this->statusCode());
        self::assertSame(['error' => 'permission_denied'], $body);
    }

    public function testEachStatementIsAuditedThroughTheApiDoor(): void
    {
        $this->setUpToken();
        $script = $this->script();
        $this->api('script/execute', ['script' => $script, 'confirmationToken' => $this->ticket($script)]);

        self::assertNotNull($this->owner);
        $events = $this->auditEvents($this->owner, 'query');
        $executed = array_values(array_filter($events, static fn ($e): bool => 'query.executed' === $e->getAction()->value));

        self::assertCount(2, $executed);
        self::assertSame('api', $executed[0]->getActor()->value);
        self::assertSame($executed[0]->getDetails()['executionId'], $executed[1]->getDetails()['executionId']);
        self::assertContains('query.prepared', array_map(static fn ($e): string => $e->getAction()->value, $events));
    }
}
