<?php

declare(strict_types=1);

namespace App\Tests\Integration\Web;

use App\Audit\AuditAction;
use App\Entity\ScriptRun;
use App\Entity\ScriptRunStatus;
use App\Repository\ScriptRunRepository;
use App\Tests\Integration\Driver\TargetDatabase;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Uid\Uuid;

#[Group('integration')]
final class ScriptRunWebTest extends WebApiTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        RecordingScriptRunLauncher::reset();
    }

    private function saveTarget(): string
    {
        $parameters = TargetDatabase::parameters('TARGET_POSTGRES_URL');
        $created = $this->call('POST', '/web/connections', ['name' => 'Target', 'engine' => 'postgresql', 'host' => $parameters->host, 'port' => $parameters->port, 'database' => $parameters->database, 'username' => $parameters->username, 'password' => $parameters->password()]);
        $id = self::arrayOf($created['connection'])['id'];
        self::assertIsString($id);

        return $id;
    }

    private function runs(): ScriptRunRepository
    {
        $runs = self::getContainer()->get(ScriptRunRepository::class);
        self::assertInstanceOf(ScriptRunRepository::class, $runs);

        return $runs;
    }

    /**
     * @param array<string, mixed> $extra
     *
     * @return array<string, mixed>
     */
    private function start(string $connection, string $script, array $extra = []): array
    {
        return $this->call('POST', '/web/connections/'.$connection.'/script/run', ['script' => $script] + $extra);
    }

    public function testRequiresASession(): void
    {
        $this->call('POST', '/web/connections/00000000-0000-7000-8000-000000000000/script/run', ['script' => 'select 1']);
        self::assertSame(401, $this->statusCode());

        $this->call('GET', '/web/script-runs/00000000-0000-7000-8000-000000000000');
        self::assertSame(401, $this->statusCode());

        $this->call('POST', '/web/script-runs/00000000-0000-7000-8000-000000000000/cancel');
        self::assertSame(401, $this->statusCode());
    }

    public function testAStartedRunIsQueuedAndHandedToTheWorkerWithTheDecryptedConnection(): void
    {
        $alice = $this->seedAndLogin();
        $id = $this->saveTarget();

        $body = $this->start($id, 'SELECT 1; SELECT 2');

        self::assertSame(202, $this->statusCode());
        $run = self::arrayOf($body['run']);
        self::assertSame('queued', $run['status']);
        self::assertSame(2, $run['total']);
        self::assertSame(0, $run['completed']);
        self::assertFalse($run['dryRun']);
        self::assertFalse($run['cancelRequested']);
        self::assertSame($id, $run['connectionId']);

        self::assertCount(1, RecordingScriptRunLauncher::$requests);
        $request = RecordingScriptRunLauncher::last();
        self::assertSame($run['id'], $request->runId->toRfc4122());
        self::assertSame('SELECT 1; SELECT 2', $request->script);
        self::assertSame(TargetDatabase::parameters('TARGET_POSTGRES_URL')->password(), $request->parameters->password());
        self::assertSame($alice->getId()->toRfc4122(), $request->audit->userId?->toRfc4122());
        self::assertSame('web', $request->audit->actor->value);
        self::assertTrue($request->writes->data && $request->writes->schema);
    }

    public function testTheResponseNeverCarriesTheScriptNorTheCredentials(): void
    {
        $this->seedAndLogin();
        $id = $this->saveTarget();

        $this->start($id, "SELECT 'valeur-secrete-du-script'");
        $content = (string) $this->client->getResponse()->getContent();

        self::assertStringNotContainsString('valeur-secrete-du-script', $content);
        self::assertStringNotContainsString(TargetDatabase::parameters('TARGET_POSTGRES_URL')->password(), $content);
    }

    public function testTheStartIsAuditedWithTheNumberOfStatementsAndNeverTheText(): void
    {
        $alice = $this->seedAndLogin();
        $id = $this->saveTarget();

        $this->start($id, "SELECT 'valeur-secrete-du-script'; SELECT 2", ['dryRun' => true]);

        $lines = array_values(array_filter($this->auditEvents($alice), static fn ($e): bool => AuditAction::ScriptStarted === $e->getAction()));
        self::assertCount(1, $lines);
        $details = $lines[0]->getDetails();
        self::assertSame(2, $details['statements']);
        self::assertTrue($details['dryRun']);
        self::assertIsString($details['textHash']);
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $details['textHash']);
        self::assertStringNotContainsString('valeur-secrete-du-script', json_encode($details, \JSON_THROW_ON_ERROR));
        self::assertSame($this->lastRun()['id'], $details['executionId']);
    }

    /** @return array<string, mixed> */
    private function lastRun(): array
    {
        $body = json_decode((string) $this->client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($body);
        $run = $body['run'];
        self::assertIsArray($run);

        $typed = [];
        foreach ($run as $key => $value) {
            $typed[(string) $key] = $value;
        }

        return $typed;
    }

    public function testAStatementTheGuardRefusesStartsNothing(): void
    {
        $this->seedAndLogin();
        $id = $this->saveTarget();

        $body = $this->start($id, "SELECT 1;\nCOMMIT");

        self::assertSame(403, $this->statusCode());
        self::assertSame('script_refused', $body['error']);
        self::assertSame([], RecordingScriptRunLauncher::$requests);
    }

    public function testAConstructOutsideTheUsualFrameNeedsConfirmationThenStartsAndTheWorkerJudgesItTheSameWay(): void
    {
        $this->seedAndLogin();
        $id = $this->saveTarget();

        $body = $this->start($id, "SELECT 1;\nCOPY t TO '/tmp/x'");
        self::assertSame(409, $this->statusCode());
        self::assertSame('confirmation_required', $body['error']);
        self::assertSame([], RecordingScriptRunLauncher::$requests);

        $this->start($id, "SELECT 1;\nCOPY t TO '/tmp/x'", ['confirmed' => true]);
        self::assertSame(202, $this->statusCode());
        self::assertTrue(RecordingScriptRunLauncher::last()->lenient);
    }

    public function testADestructiveScriptNeedsConfirmationThenStarts(): void
    {
        $this->seedAndLogin();
        $id = $this->saveTarget();

        $body = $this->start($id, 'DELETE FROM somewhere');
        self::assertSame(409, $this->statusCode());
        self::assertSame('confirmation_required', $body['error']);
        self::assertSame([], RecordingScriptRunLauncher::$requests);

        $this->start($id, 'DELETE FROM somewhere', ['confirmed' => true]);
        self::assertSame(202, $this->statusCode());
        self::assertCount(1, RecordingScriptRunLauncher::$requests);
        self::assertTrue(RecordingScriptRunLauncher::last()->confirmed);
    }

    public function testAnEmptyScriptAndATimeoutOutOfBoundsAreRefused(): void
    {
        $this->seedAndLogin();
        $id = $this->saveTarget();

        $empty = $this->start($id, '   ');
        self::assertSame(403, $this->statusCode(), 'un script vide est refusé comme à la console');
        self::assertSame('query_refused', $empty['error']);

        $body = $this->start($id, 'SELECT 1', ['timeoutMs' => 5]);
        self::assertSame(422, $this->statusCode());
        self::assertSame([], RecordingScriptRunLauncher::$requests);
        self::assertArrayHasKey('error', $body);
    }

    public function testWithoutAWorkerTheRunIsEndedFailedAndTheAnswerSaysSo(): void
    {
        $alice = $this->seedAndLogin();
        $id = $this->saveTarget();
        RecordingScriptRunLauncher::$answer = 'unavailable';

        $body = $this->start($id, 'SELECT 1');

        self::assertSame(503, $this->statusCode());
        self::assertSame('script_worker_unavailable', $body['error']);
        $runs = $this->runs()->findBy(['user' => $alice->getId()]);
        self::assertCount(1, $runs);
        self::assertSame(ScriptRunStatus::Failed, $runs[0]->getStatus(), 'une exécution qui n\'a pas démarré ne reste pas en file');
    }

    public function testAWorkerThatIsFullAnswersBusyWithARetryDelay(): void
    {
        $this->seedAndLogin();
        $id = $this->saveTarget();
        RecordingScriptRunLauncher::$answer = 'busy';

        $body = $this->start($id, 'SELECT 1');

        self::assertSame(503, $this->statusCode());
        self::assertSame('script_worker_busy', $body['error']);
        self::assertSame('5', $this->client->getResponse()->headers->get('Retry-After'));
    }

    public function testARunIsReadByItsOwnerOnly(): void
    {
        $this->seedAndLogin();
        $id = $this->saveTarget();
        $runId = $this->startedRunId($id);

        $body = $this->call('GET', '/web/script-runs/'.$runId);
        self::assertSame(200, $this->statusCode());
        self::assertSame($runId, self::arrayOf($body['run'])['id']);

        $this->call('POST', '/web/logout');
        $this->seedUser('bob');
        $this->login('bob');
        $this->call('GET', '/web/script-runs/'.$runId);
        self::assertSame(404, $this->statusCode());

        $this->call('GET', '/web/script-runs/'.Uuid::v7()->toRfc4122());
        self::assertSame(404, $this->statusCode());
    }

    private function startedRunId(string $connection): string
    {
        $this->start($connection, 'SELECT 1');
        $id = $this->lastRun()['id'];
        self::assertIsString($id);

        return $id;
    }

    public function testRunningRunsWithoutAHeartbeatAreSeenAsInterruptedWhenRead(): void
    {
        $this->seedAndLogin();
        $id = $this->saveTarget();
        $runId = $this->startedRunId($id);
        $entity = $this->runs()->find(Uuid::fromString($runId));
        \assert($entity instanceof ScriptRun);
        $entity->start(new \DateTimeImmutable('-5 minutes'));
        $this->runs()->save($entity);

        $body = $this->call('GET', '/web/script-runs/'.$runId);

        self::assertSame('interrupted', self::arrayOf($body['run'])['status']);
        self::assertNotNull(self::arrayOf($body['run'])['finishedAt']);
    }

    public function testACancelIsAskedOnALiveRunAndNotOnAFinishedOne(): void
    {
        $this->seedAndLogin();
        $id = $this->saveTarget();
        $runId = $this->startedRunId($id);

        $body = $this->call('POST', '/web/script-runs/'.$runId.'/cancel');
        self::assertSame(200, $this->statusCode());
        self::assertTrue(self::arrayOf($body['run'])['cancelRequested']);

        $this->call('POST', '/web/script-runs/'.$runId.'/cancel');
        self::assertSame(200, $this->statusCode(), 'demander deux fois ne change rien');

        $entity = $this->runs()->find(Uuid::fromString($runId));
        \assert($entity instanceof ScriptRun);
        $this->entityManagerClear();
        $entity = $this->runs()->find(Uuid::fromString($runId));
        \assert($entity instanceof ScriptRun);
        $entity->start(new \DateTimeImmutable());
        $entity->finish(ScriptRunStatus::Committed, new \DateTimeImmutable());
        $this->runs()->save($entity);

        $body = $this->call('POST', '/web/script-runs/'.$runId.'/cancel');
        self::assertSame(409, $this->statusCode());
        self::assertSame('run_finished', $body['error']);
    }

    public function testAnotherAccountCannotCancel(): void
    {
        $this->seedAndLogin();
        $id = $this->saveTarget();
        $runId = $this->startedRunId($id);
        $this->call('POST', '/web/logout');
        $this->seedUser('bob');
        $this->login('bob');

        $this->call('POST', '/web/script-runs/'.$runId.'/cancel');

        self::assertSame(404, $this->statusCode());
    }

    private function entityManagerClear(): void
    {
        $em = self::getContainer()->get(\Doctrine\ORM\EntityManagerInterface::class);
        \assert($em instanceof \Doctrine\ORM\EntityManagerInterface);
        $em->clear();
    }
}
