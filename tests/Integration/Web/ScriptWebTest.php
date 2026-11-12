<?php

declare(strict_types=1);

namespace App\Tests\Integration\Web;

use App\Audit\AuditAction;
use App\Audit\AuditOutcome;
use App\Core\Connection\Engine;
use App\Driver\DbalConnector;
use App\Tests\Integration\Driver\TargetDatabase;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\Group;

#[Group('integration')]
final class ScriptWebTest extends WebApiTestCase
{
    private ?Connection $target = null;
    /** @var list<string> */
    private array $tables = [];

    protected function tearDown(): void
    {
        foreach ($this->tables as $table) {
            $this->target?->executeStatement('DROP TABLE IF EXISTS '.$table);
        }
        $this->target?->close();
        parent::tearDown();
    }

    private function target(): Connection
    {
        return $this->target ??= (new DbalConnector())->connect(Engine::PostgreSql, TargetDatabase::parameters('TARGET_POSTGRES_URL'));
    }

    private function scratch(): string
    {
        $name = 'tdb_webscript_'.bin2hex(random_bytes(4));
        $this->tables[] = $name;
        $this->target()->executeStatement(\sprintf('CREATE TABLE %s (id integer NOT NULL PRIMARY KEY, v varchar(20))', $name));

        return $name;
    }

    private function saveTarget(string $engine = 'postgresql', string $env = 'TARGET_POSTGRES_URL'): string
    {
        $parameters = TargetDatabase::parameters($env);
        $created = $this->call('POST', '/web/connections', ['name' => 'Target', 'engine' => $engine, 'host' => $parameters->host, 'port' => $parameters->port, 'database' => $parameters->database, 'username' => $parameters->username, 'password' => $parameters->password()]);
        $id = self::arrayOf($created['connection'])['id'];
        self::assertIsString($id);

        return $id;
    }

    private function rows(string $table): int
    {
        return self::int($this->target()->fetchOne('SELECT count(*) FROM '.$table));
    }

    private static function int(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }

    public function testRequiresASession(): void
    {
        $this->call('POST', '/web/connections/00000000-0000-7000-8000-000000000000/script/preview', ['script' => 'select 1']);

        self::assertSame(401, $this->statusCode());
    }

    public function testPreviewJudgesEveryStatementAndExecutesNothing(): void
    {
        $this->seedAndLogin();
        $id = $this->saveTarget();
        $table = $this->scratch();

        $body = $this->call('POST', '/web/connections/'.$id.'/script/preview', ['script' => "INSERT INTO $table VALUES (1, 'a');\nDELETE FROM $table;\nSELECT * FROM $table"]);

        self::assertSame(200, $this->statusCode());
        self::assertTrue($body['canRun']);
        self::assertTrue($body['needsConfirmation']);
        self::assertSame(1, $body['destructiveCount']);
        self::assertNull($body['firstRefused']);
        $steps = array_map(static fn (mixed $s): array => self::arrayOf($s), self::arrayOf($body['steps']));
        self::assertSame(['allowed', 'needs_confirmation', 'allowed'], array_column($steps, 'verdict'));
        self::assertSame(['write', 'write', 'read'], array_column($steps, 'kind'));
        self::assertSame('destructive_without_where', $steps[1]['reason']);
        self::assertSame("INSERT INTO $table VALUES (1, 'a')", $steps[0]['sql']);
        self::assertSame(['transactional' => true, 'atomic' => true, 'dryRun' => ['available' => true, 'reason' => null], 'warnings' => []], [
            'transactional' => $body['transactional'], 'atomic' => $body['atomic'], 'dryRun' => $body['dryRun'], 'warnings' => $body['warnings'],
        ]);
        self::assertSame(0, $this->rows($table));
    }

    public function testPreviewNamesTheRefusedStatement(): void
    {
        $this->seedAndLogin();
        $id = $this->saveTarget();

        $body = $this->call('POST', '/web/connections/'.$id.'/script/preview', ['script' => "SELECT 1;\nCOPY t TO '/tmp/x'"]);

        self::assertSame(200, $this->statusCode());
        self::assertFalse($body['canRun']);
        self::assertSame(['index' => 1, 'reason' => 'forbidden_construct'], $body['firstRefused']);
    }

    public function testExecuteCommitsTheScriptAndReportsEachStatement(): void
    {
        $this->seedAndLogin();
        $id = $this->saveTarget();
        $table = $this->scratch();

        $body = $this->call('POST', '/web/connections/'.$id.'/script/execute', ['script' => "INSERT INTO $table VALUES (1, 'a'); INSERT INTO $table VALUES (2, 'b'); SELECT * FROM $table"]);

        self::assertSame(200, $this->statusCode());
        self::assertSame('committed', $body['status']);
        self::assertFalse($body['dryRun']);
        self::assertSame([0, 1, 2], $body['applied']);
        self::assertNull($body['failed']);
        self::assertSame([], $body['notRun']);
        $steps = array_map(static fn (mixed $s): array => self::arrayOf($s), self::arrayOf($body['steps']));
        self::assertSame([1, 1, null], array_column($steps, 'affectedRows'));
        self::assertSame(2, $steps[2]['returnedRows']);
        self::assertSame(2, $this->rows($table));
    }

    public function testAFailureStopsAndRollsBackAndSaysWhichStatementFailed(): void
    {
        $this->seedAndLogin();
        $id = $this->saveTarget();
        $table = $this->scratch();

        $body = $this->call('POST', '/web/connections/'.$id.'/script/execute', ['script' => "INSERT INTO $table VALUES (1, 'a'); INSERT INTO $table VALUES (1, 'dup'); INSERT INTO $table VALUES (3, 'c')"]);

        self::assertSame(200, $this->statusCode());
        self::assertSame('rolled_back', $body['status']);
        self::assertSame([], $body['applied']);
        self::assertSame(1, $body['failed']);
        self::assertSame([2], $body['notRun']);
        self::assertSame('engine_error', $body['failureReason']);
        $steps = array_map(static fn (mixed $s): array => self::arrayOf($s), self::arrayOf($body['steps']));
        self::assertSame(['rolled_back', 'failed', 'not_run'], array_column($steps, 'status'));
        self::assertIsString($steps[1]['error']);
        self::assertSame(0, $this->rows($table));
    }

    public function testADryRunRunsForRealThenCancels(): void
    {
        $this->seedAndLogin();
        $id = $this->saveTarget();
        $table = $this->scratch();

        $body = $this->call('POST', '/web/connections/'.$id.'/script/execute', ['script' => "INSERT INTO $table VALUES (1, 'a')", 'dryRun' => true]);

        self::assertSame(200, $this->statusCode());
        self::assertSame('dry_run', $body['status']);
        self::assertTrue($body['dryRun']);
        self::assertSame([], $body['applied']);
        self::assertSame(0, $this->rows($table));
    }

    public function testADestructiveScriptWaitsForOneConfirmationThenRuns(): void
    {
        $this->seedAndLogin();
        $id = $this->saveTarget();
        $table = $this->scratch();
        $script = "INSERT INTO $table VALUES (1, 'a'); DELETE FROM $table; DROP TABLE $table";

        $first = $this->call('POST', '/web/connections/'.$id.'/script/execute', ['script' => $script]);
        self::assertSame(409, $this->statusCode());
        self::assertSame('confirmation_required', $first['error']);
        self::assertSame(['reason' => 'destructive_script', 'destructiveCount' => 2], ['reason' => $first['reason'], 'destructiveCount' => $first['destructiveCount']]);
        self::assertSame(0, $this->rows($table));

        $second = $this->call('POST', '/web/connections/'.$id.'/script/execute', ['script' => $script, 'confirmed' => true]);
        self::assertSame(200, $this->statusCode());
        self::assertSame('committed', $second['status']);
        self::assertSame(0, self::int($this->target()->fetchOne("SELECT count(*) FROM pg_tables WHERE tablename = '$table'")));
    }

    public function testARefusedStatementStopsTheScriptBeforeAnythingRuns(): void
    {
        $this->seedAndLogin();
        $id = $this->saveTarget();
        $table = $this->scratch();

        $body = $this->call('POST', '/web/connections/'.$id.'/script/execute', ['script' => "INSERT INTO $table VALUES (1, 'a'); COPY $table TO '/tmp/x'", 'confirmed' => true]);

        self::assertSame(403, $this->statusCode());
        self::assertSame('script_refused', $body['error']);
        self::assertSame(['reason' => 'forbidden_construct', 'statement' => 1], ['reason' => $body['reason'], 'statement' => $body['statement']]);
        self::assertSame(0, $this->rows($table));
    }

    public function testAnUnsplittableOrOversizedScriptIsRejected(): void
    {
        $this->seedAndLogin();
        $id = $this->saveTarget();

        $body = $this->call('POST', '/web/connections/'.$id.'/script/execute', ['script' => "SELECT 'oops"]);
        self::assertSame(422, $this->statusCode());
        self::assertSame('invalid_script', $body['error']);

        $body = $this->call('POST', '/web/connections/'.$id.'/script/preview', ['script' => implode(';', array_fill(0, 501, 'SELECT 1'))]);
        self::assertSame(422, $this->statusCode());
        self::assertSame('script_too_large', $body['error']);
    }

    public function testADryRunTheEngineCannotDoIsRefusedWithItsReason(): void
    {
        $this->seedAndLogin();
        $id = $this->saveTarget('mysql', 'TARGET_MYSQL_URL');

        $body = $this->call('POST', '/web/connections/'.$id.'/script/execute', ['script' => 'CREATE TABLE tdb_never (id int)', 'dryRun' => true]);

        self::assertSame(409, $this->statusCode());
        self::assertSame('dry_run_unavailable', $body['error']);
        self::assertSame('implicit_commit', $body['reason']);
    }

    public function testEveryStatementIsAuditedWithACommonExecutionIdAndItsRank(): void
    {
        $user = $this->seedAndLogin();
        $id = $this->saveTarget();
        $table = $this->scratch();

        $body = $this->call('POST', '/web/connections/'.$id.'/script/execute', ['script' => "INSERT INTO $table VALUES (1, 'a'); INSERT INTO $table VALUES (1, 'dup'); INSERT INTO $table VALUES (3, 'c')"]);

        $events = $this->auditEvents($user, 'query');
        usort($events, static fn ($a, $b): int => $a->getDetails()['rank'] <=> $b->getDetails()['rank']);
        self::assertCount(2, $events, 'une ligne par instruction envoyée au moteur ; la troisième n\'est jamais partie');
        self::assertSame(['query.executed', 'query.failed'], array_map(static fn ($e): string => $e->getAction()->value, $events));
        self::assertSame([AuditOutcome::Success, AuditOutcome::Failed], array_map(static fn ($e): AuditOutcome => $e->getOutcome(), $events));
        $first = $events[0]->getDetails();
        self::assertSame($body['executionId'], $first['executionId']);
        self::assertSame($first['executionId'], $events[1]->getDetails()['executionId']);
        self::assertSame([1, 3], [$first['rank'], $first['total']]);
        self::assertSame([2, 3], [$events[1]->getDetails()['rank'], $events[1]->getDetails()['total']]);
        self::assertSame("INSERT INTO $table VALUES (1, 'a')", $first['sql']);
        self::assertSame('rolled_back', $first['status']);
        self::assertTrue($first['script']);
        self::assertIsString($events[1]->getDetails()['error']);
        self::assertSame(AuditAction::QueryFailed, $events[1]->getAction());
    }

    public function testARefusedScriptIsAuditedOnceOnTheOffendingStatement(): void
    {
        $user = $this->seedAndLogin();
        $id = $this->saveTarget();

        $this->call('POST', '/web/connections/'.$id.'/script/execute', ['script' => "SELECT 1; COPY t TO '/tmp/x'", 'confirmed' => true]);

        $events = $this->auditEvents($user, 'query');
        self::assertCount(1, $events);
        self::assertSame(AuditAction::QueryRefused, $events[0]->getAction());
        self::assertSame(['forbidden_construct', 2, 2], [$events[0]->getDetails()['reason'], $events[0]->getDetails()['rank'], $events[0]->getDetails()['total']]);
    }

    public function testAConfirmationRequestIsNotAnExecution(): void
    {
        $user = $this->seedAndLogin();
        $id = $this->saveTarget();

        $this->call('POST', '/web/connections/'.$id.'/script/execute', ['script' => 'DROP TABLE nope_tdb']);

        self::assertSame(409, $this->statusCode());
        self::assertSame([], $this->auditEvents($user, 'query'));
    }
}
