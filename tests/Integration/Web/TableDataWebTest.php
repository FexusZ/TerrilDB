<?php

declare(strict_types=1);

namespace App\Tests\Integration\Web;

use App\Core\Connection\Engine;
use App\Driver\DbalConnector;
use App\Tests\Integration\Driver\TargetDatabase;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\Group;

#[Group('integration')]
final class TableDataWebTest extends WebApiTestCase
{
    private ?Connection $target = null;

    /** @var list<string> */
    private array $tables = [];

    protected function tearDown(): void
    {
        foreach ($this->tables as $table) {
            $this->target?->executeStatement(\sprintf('DROP TABLE IF EXISTS "%s"', $table));
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
        $name = 'tdb_webdata_'.bin2hex(random_bytes(4));
        $this->tables[] = $name;
        $this->target()->executeStatement(\sprintf('CREATE TABLE "%s" (id integer NOT NULL PRIMARY KEY, name character varying(40) NOT NULL, note text)', $name));
        $this->target()->executeStatement(\sprintf("INSERT INTO \"%s\" VALUES (1, 'alpha', NULL), (2, 'beta', 'b')", $name));

        return $name;
    }

    private function saveTarget(): string
    {
        $parameters = TargetDatabase::parameters('TARGET_POSTGRES_URL');
        $created = $this->call('POST', '/web/connections', ['name' => 'Target', 'engine' => 'postgresql', 'host' => $parameters->host, 'port' => $parameters->port, 'database' => $parameters->database, 'username' => $parameters->username, 'password' => $parameters->password()]);
        $id = self::arrayOf($created['connection'])['id'];
        self::assertIsString($id);

        return $id;
    }

    /**
     * @return list<list<mixed>>
     */
    private function rows(string $table): array
    {
        return array_map(array_values(...), $this->target()->fetchAllAssociative(\sprintf('SELECT id, name, note FROM "%s" ORDER BY id', $table)));
    }

    public function testRequiresASession(): void
    {
        $this->call('POST', '/web/connections/00000000-0000-7000-8000-000000000000/tables/t/data/preview', []);

        self::assertSame(401, $this->statusCode());
    }

    public function testPreviewShowsTheSqlAndHowManyRowsEachStatementFindsWithoutWriting(): void
    {
        $this->seedAndLogin();
        $id = $this->saveTarget();
        $table = $this->scratch();

        $body = $this->call('POST', '/web/connections/'.$id.'/tables/'.$table.'/data/preview', [
            'updates' => [['original' => ['id' => 1, 'name' => 'alpha', 'note' => null], 'changes' => ['name' => 'one']]],
            'inserts' => [['values' => ['id' => 3, 'name' => 'gamma']]],
            'deletes' => [['original' => ['id' => 2, 'name' => 'beta', 'note' => 'b']]],
        ]);

        self::assertSame(200, $this->statusCode());
        self::assertTrue($body['ok']);
        $statements = array_map(static fn (mixed $s): array => self::arrayOf($s), self::arrayOf($body['statements']));
        self::assertSame(["UPDATE \"{$table}\" SET \"name\" = 'one' WHERE \"id\" = 1 AND \"name\" = 'alpha'", 'update', false, 1], [$statements[0]['sql'], $statements[0]['operation'], $statements[0]['destructive'], $statements[0]['matched']]);
        self::assertSame(['delete', true, 1], [$statements[1]['operation'], $statements[1]['destructive'], $statements[1]['matched']]);
        self::assertSame(['insert', null], [$statements[2]['operation'], $statements[2]['matched']]);
        self::assertFalse($body['asynchronous']);
        self::assertSame([[1, 'alpha', null], [2, 'beta', 'b']], $this->rows($table));
    }

    public function testPreviewFlagsARowThatIsNoLongerThere(): void
    {
        $this->seedAndLogin();
        $id = $this->saveTarget();
        $table = $this->scratch();

        $body = $this->call('POST', '/web/connections/'.$id.'/tables/'.$table.'/data/preview', ['updates' => [['original' => ['id' => 1, 'name' => 'stale', 'note' => null], 'changes' => ['name' => 'one']]]]);

        self::assertFalse($body['ok']);
        self::assertSame(0, self::arrayOf(self::arrayOf($body['statements'])[0])['matched']);
    }

    public function testAppliesAndAuditsTheChange(): void
    {
        $user = $this->seedAndLogin();
        $id = $this->saveTarget();
        $table = $this->scratch();

        $this->call('POST', '/web/connections/'.$id.'/tables/'.$table.'/data/apply', [
            'updates' => [['original' => ['id' => 1, 'name' => 'alpha', 'note' => null], 'changes' => ['note' => 'n']]],
            'inserts' => [['values' => ['id' => 3, 'name' => 'gamma']]],
        ]);

        self::assertSame(200, $this->statusCode());
        self::assertSame([[1, 'alpha', 'n'], [2, 'beta', 'b'], [3, 'gamma', null]], $this->rows($table));
        $events = $this->auditEvents($user, 'data');
        self::assertCount(1, $events);
        self::assertSame('data.changed', $events[0]->getAction()->value);
        self::assertSame(1, $events[0]->getDetails()['updates']);
        self::assertSame(1, $events[0]->getDetails()['inserts']);
        self::assertSame(0, $events[0]->getDetails()['deletes']);
    }

    public function testTheAuditKeepsTheShapeOfTheChangeButNeverTheValues(): void
    {
        $user = $this->seedAndLogin();
        $id = $this->saveTarget();
        $table = $this->scratch();

        $this->call('POST', '/web/connections/'.$id.'/tables/'.$table.'/data/apply', [
            'updates' => [['original' => ['id' => 1, 'name' => 'alpha', 'note' => null], 'changes' => ['note' => 'hunter2-secret']]],
            'inserts' => [['values' => ['id' => 3, 'name' => 'gamma-secret']]],
        ]);

        $details = $this->auditEvents($user, 'data')[0]->getDetails();
        self::assertSame(['note', 'id', 'name'], $details['columns']);
        $sql = $details['sql'];
        self::assertIsString($sql);
        self::assertStringContainsString('UPDATE', $sql);
        self::assertStringNotContainsString('hunter2-secret', $sql);
        self::assertStringNotContainsString('gamma-secret', $sql);
        self::assertStringNotContainsString('alpha', $sql);
    }

    public function testAnEngineRefusalIsAuditedWithoutTheEngineMessage(): void
    {
        $user = $this->seedAndLogin();
        $id = $this->saveTarget();
        $table = $this->scratch();

        $this->call('POST', '/web/connections/'.$id.'/tables/'.$table.'/data/apply', ['inserts' => [['values' => ['id' => 1, 'name' => 'dup-secret']]]]);

        $details = $this->auditEvents($user, 'data')[0]->getDetails();
        self::assertSame('engine_refused', $details['error']);
        self::assertStringNotContainsString('dup-secret', json_encode($details, \JSON_THROW_ON_ERROR));
        self::assertStringNotContainsString('(id)=(1)', json_encode($details, \JSON_THROW_ON_ERROR));
    }

    public function testDeletingNeedsConfirmation(): void
    {
        $this->seedAndLogin();
        $id = $this->saveTarget();
        $table = $this->scratch();
        $draft = ['deletes' => [['original' => ['id' => 2, 'name' => 'beta', 'note' => 'b']]]];

        $body = $this->call('POST', '/web/connections/'.$id.'/tables/'.$table.'/data/apply', $draft);

        self::assertSame(409, $this->statusCode());
        self::assertSame('confirmation_required', $body['error'] ?? null);
        self::assertSame('delete_rows', $body['reason'] ?? null);
        self::assertCount(2, $this->rows($table));

        $this->call('POST', '/web/connections/'.$id.'/tables/'.$table.'/data/apply', $draft + ['confirmed' => true]);

        self::assertSame(200, $this->statusCode());
        self::assertSame([[1, 'alpha', null]], $this->rows($table));
    }

    public function testARowChangedMeanwhileIsRefused(): void
    {
        $this->seedAndLogin();
        $id = $this->saveTarget();
        $table = $this->scratch();

        $body = $this->call('POST', '/web/connections/'.$id.'/tables/'.$table.'/data/apply', ['updates' => [['original' => ['id' => 1, 'name' => 'stale', 'note' => null], 'changes' => ['name' => 'one']]]]);

        self::assertSame(409, $this->statusCode());
        self::assertSame('data_changed', $body['error'] ?? null);
        self::assertSame([[1, 'alpha', null], [2, 'beta', 'b']], $this->rows($table));
    }

    public function testRefusesAnUnknownColumn(): void
    {
        $this->seedAndLogin();
        $id = $this->saveTarget();
        $table = $this->scratch();

        $body = $this->call('POST', '/web/connections/'.$id.'/tables/'.$table.'/data/apply', ['inserts' => [['values' => ['nope' => 1]]]]);

        self::assertSame(422, $this->statusCode());
        self::assertSame('invalid_data_change', $body['error'] ?? null);
    }

    public function testTheEngineRefusalIsReported(): void
    {
        $this->seedAndLogin();
        $id = $this->saveTarget();
        $table = $this->scratch();

        $body = $this->call('POST', '/web/connections/'.$id.'/tables/'.$table.'/data/apply', ['inserts' => [['values' => ['id' => 1, 'name' => 'dup']]]]);

        self::assertSame(409, $this->statusCode());
        self::assertSame('data_change_failed', $body['error'] ?? null);
        self::assertCount(2, $this->rows($table));
    }
}
