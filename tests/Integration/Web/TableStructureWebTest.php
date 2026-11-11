<?php

declare(strict_types=1);

namespace App\Tests\Integration\Web;

use App\Core\Connection\Engine;
use App\Driver\DbalConnector;
use App\Tests\Integration\Driver\TargetDatabase;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\Group;

#[Group('integration')]
final class TableStructureWebTest extends WebApiTestCase
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
        $name = 'tdb_web_'.bin2hex(random_bytes(4));
        $this->tables[] = $name;
        $this->target()->executeStatement(\sprintf('CREATE TABLE "%s" (id integer NOT NULL PRIMARY KEY, name character varying(40) NOT NULL, note text)', $name));

        return $name;
    }

    private function saveTarget(string $envVar = 'TARGET_POSTGRES_URL'): string
    {
        $parameters = TargetDatabase::parameters($envVar);
        $created = $this->call('POST', '/web/connections', ['name' => 'Target', 'engine' => TargetDatabase::engine($envVar)->value, 'host' => $parameters->host, 'port' => $parameters->port, 'database' => $parameters->database, 'username' => $parameters->username, 'password' => $parameters->password()]);
        $id = self::arrayOf($created['connection'])['id'];
        self::assertIsString($id);

        return $id;
    }

    /**
     * Le brouillon « identique à la table actuelle », tel que l'interface le construit à partir de la lecture de la table.
     *
     * @param array<string, mixed> $table
     *
     * @return array<string, mixed>
     */
    private static function draftOf(array $table): array
    {
        return [
            'name' => $table['name'],
            'columns' => array_map(static fn (mixed $c): array => ['originalName' => self::arrayOf($c)['name']] + self::arrayOf($c), self::arrayOf($table['columns'])),
            'indexes' => array_map(static fn (mixed $i): array => ['originalName' => self::arrayOf($i)['name'], 'kind' => null, 'granularity' => null] + self::arrayOf($i), self::arrayOf($table['indexes'])),
            'foreignKeys' => array_map(static fn (mixed $f): array => ['originalName' => self::arrayOf($f)['name']] + self::arrayOf($f), self::arrayOf($table['foreignKeys'])),
        ];
    }

    /**
     * @return array{array<string, mixed>, string}
     */
    private function load(string $id, string $table): array
    {
        $body = $this->call('GET', '/web/connections/'.$id.'/tables/'.$table);
        self::assertSame(200, $this->statusCode());
        /** @var array<string, mixed> $detail */
        $detail = self::arrayOf($body['table']);
        self::assertIsString($detail['fingerprint']);

        return [$detail, $detail['fingerprint']];
    }

    /**
     * @param array<string, mixed> $draft
     *
     * @return array<string, mixed>
     */
    private function withNewColumn(array $draft, string $name = 'qty'): array
    {
        $draft['columns'] = [...self::arrayOf($draft['columns']), ['originalName' => null, 'name' => $name, 'type' => 'integer', 'nullable' => false, 'default' => '0', 'autoIncrement' => false]];

        return $draft;
    }

    /**
     * @return list<string>
     */
    private function columnsOf(string $table): array
    {
        return array_map(static fn (mixed $name): string => \is_string($name) ? $name : '', $this->target()->fetchFirstColumn('SELECT column_name FROM information_schema.columns WHERE table_name = ? ORDER BY ordinal_position', [$table]));
    }

    public function testRequiresASession(): void
    {
        $this->call('POST', '/web/connections/00000000-0000-7000-8000-000000000000/tables/t/structure/preview', ['draft' => []]);

        self::assertSame(401, $this->statusCode());
    }

    public function testReadingATableGivesItsFingerprint(): void
    {
        $this->seedAndLogin();
        $id = $this->saveTarget();
        $table = $this->scratch();

        [, $fingerprint] = $this->load($id, $table);
        [, $again] = $this->load($id, $table);

        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $fingerprint);
        self::assertSame($fingerprint, $again);
    }

    public function testPreviewShowsTheSqlWithoutChangingAnything(): void
    {
        $this->seedAndLogin();
        $id = $this->saveTarget();
        $table = $this->scratch();
        [$detail, $fingerprint] = $this->load($id, $table);

        $body = $this->call('POST', '/web/connections/'.$id.'/tables/'.$table.'/structure/preview', ['draft' => $this->withNewColumn(self::draftOf($detail)), 'fingerprint' => $fingerprint]);

        self::assertSame(200, $this->statusCode());
        self::assertSame([['sql' => \sprintf('ALTER TABLE "%s" ADD COLUMN "qty" integer NOT NULL DEFAULT 0', $table), 'operation' => 'add_column', 'subject' => 'qty', 'destructive' => false]], $body['statements']);
        self::assertTrue($body['transactional']);
        self::assertFalse($body['destructive']);
        self::assertFalse($body['stale']);
        self::assertSame(['id', 'name', 'note'], $this->columnsOf($table), 'a preview never writes');
    }

    public function testPreviewSaysWhenTheTableChangedSinceItWasLoaded(): void
    {
        $this->seedAndLogin();
        $id = $this->saveTarget();
        $table = $this->scratch();
        [$detail, $fingerprint] = $this->load($id, $table);
        $this->target()->executeStatement(\sprintf('ALTER TABLE "%s" ADD COLUMN other text', $table));

        $body = $this->call('POST', '/web/connections/'.$id.'/tables/'.$table.'/structure/preview', ['draft' => self::draftOf($detail), 'fingerprint' => $fingerprint]);

        self::assertSame(200, $this->statusCode());
        self::assertTrue($body['stale']);
    }

    public function testAppliesAChangeThatDestroysNothingAndReturnsTheNewTable(): void
    {
        $user = $this->seedAndLogin();
        $id = $this->saveTarget();
        $table = $this->scratch();
        [$detail, $fingerprint] = $this->load($id, $table);

        $body = $this->call('POST', '/web/connections/'.$id.'/tables/'.$table.'/structure/apply', ['draft' => $this->withNewColumn(self::draftOf($detail)), 'fingerprint' => $fingerprint]);

        self::assertSame(200, $this->statusCode());
        self::assertSame(['id', 'name', 'note', 'qty'], $this->columnsOf($table));
        $new = self::arrayOf($body['table']);
        self::assertNotSame($fingerprint, $new['fingerprint']);
        self::assertCount(4, self::arrayOf($new['columns']));
        $events = $this->auditEvents($user, 'schema');
        self::assertCount(1, $events);
        self::assertSame('schema.changed', $events[0]->getAction()->value);
        self::assertSame(\sprintf('ALTER TABLE "%s" ADD COLUMN "qty" integer NOT NULL DEFAULT 0', $table), $events[0]->getDetails()['sql']);
    }

    public function testAChangeThatDestroysDataNeedsConfirmationAndListsWhatWillBeLost(): void
    {
        $this->seedAndLogin();
        $id = $this->saveTarget();
        $table = $this->scratch();
        [$detail, $fingerprint] = $this->load($id, $table);
        $draft = self::draftOf($detail);
        $draft['columns'] = array_values(array_filter(self::arrayOf($draft['columns']), static fn (mixed $c): bool => 'note' !== self::arrayOf($c)['name']));

        $body = $this->call('POST', '/web/connections/'.$id.'/tables/'.$table.'/structure/apply', ['draft' => $draft, 'fingerprint' => $fingerprint]);

        self::assertSame(409, $this->statusCode());
        self::assertSame('confirmation_required', $body['error'] ?? null);
        self::assertSame([['sql' => \sprintf('ALTER TABLE "%s" DROP COLUMN "note"', $table), 'operation' => 'drop_column', 'subject' => 'note', 'destructive' => true]], $body['statements']);
        self::assertSame(['id', 'name', 'note'], $this->columnsOf($table));

        $this->call('POST', '/web/connections/'.$id.'/tables/'.$table.'/structure/apply', ['draft' => $draft, 'fingerprint' => $fingerprint, 'confirmed' => true]);

        self::assertSame(200, $this->statusCode());
        self::assertSame(['id', 'name'], $this->columnsOf($table));
    }

    public function testRefusesAnApplyBasedOnAStaleFingerprint(): void
    {
        $this->seedAndLogin();
        $id = $this->saveTarget();
        $table = $this->scratch();
        [$detail, $fingerprint] = $this->load($id, $table);
        $this->target()->executeStatement(\sprintf('ALTER TABLE "%s" ADD COLUMN other text', $table));

        $body = $this->call('POST', '/web/connections/'.$id.'/tables/'.$table.'/structure/apply', ['draft' => $this->withNewColumn(self::draftOf($detail)), 'fingerprint' => $fingerprint]);

        self::assertSame(409, $this->statusCode());
        self::assertSame('schema_changed', $body['error'] ?? null);
        self::assertSame(['id', 'name', 'note', 'other'], $this->columnsOf($table));
    }

    public function testRefusesAnInvalidTypeBeforeWritingAnything(): void
    {
        $this->seedAndLogin();
        $id = $this->saveTarget();
        $table = $this->scratch();
        [$detail, $fingerprint] = $this->load($id, $table);
        $draft = $this->withNewColumn(self::draftOf($detail));
        $columns = self::arrayOf($draft['columns']);
        $columns[3] = ['type' => 'int; DROP TABLE users'] + self::arrayOf($columns[3]);
        $draft['columns'] = $columns;

        $body = $this->call('POST', '/web/connections/'.$id.'/tables/'.$table.'/structure/apply', ['draft' => $draft, 'fingerprint' => $fingerprint]);

        self::assertSame(422, $this->statusCode());
        self::assertSame('invalid_schema_change', $body['error'] ?? null);
        self::assertSame(['id', 'name', 'note'], $this->columnsOf($table));
    }

    public function testAFailedChangeIsRolledBackOnPostgreSqlAndExplainedAndAudited(): void
    {
        $user = $this->seedAndLogin();
        $id = $this->saveTarget();
        $table = $this->scratch();
        $this->target()->executeStatement(\sprintf("INSERT INTO \"%s\" VALUES (1, 'not a number', NULL)", $table));
        [$detail, $fingerprint] = $this->load($id, $table);
        $draft = $this->withNewColumn(self::draftOf($detail), 'first');
        $columns = self::arrayOf($draft['columns']);
        $columns[1] = ['type' => 'integer'] + self::arrayOf($columns[1]);
        $draft['columns'] = $columns;

        $body = $this->call('POST', '/web/connections/'.$id.'/tables/'.$table.'/structure/apply', ['draft' => $draft, 'fingerprint' => $fingerprint, 'confirmed' => true]);

        self::assertSame(409, $this->statusCode());
        self::assertSame('schema_change_failed', $body['error'] ?? null);
        self::assertSame([], $body['applied']);
        self::assertIsString(self::arrayOf($body['failed'])['sql']);
        self::assertNotSame('', $body['message']);
        self::assertSame(['id', 'name', 'note'], $this->columnsOf($table), 'the transaction was rolled back');
        $events = $this->auditEvents($user, 'schema');
        self::assertSame('schema.failed', $events[0]->getAction()->value);
    }

    public function testPreviewsAndDropsTheTableAfterConfirmation(): void
    {
        $this->seedAndLogin();
        $id = $this->saveTarget();
        $table = $this->scratch();
        [, $fingerprint] = $this->load($id, $table);

        $preview = $this->call('POST', '/web/connections/'.$id.'/tables/'.$table.'/structure/preview', ['drop' => true, 'fingerprint' => $fingerprint]);
        self::assertSame(200, $this->statusCode());
        self::assertTrue($preview['destructive']);
        self::assertSame(\sprintf('DROP TABLE "%s"', $table), self::arrayOf(self::arrayOf($preview['statements'])[0])['sql']);

        $this->call('POST', '/web/connections/'.$id.'/tables/'.$table.'/structure/apply', ['drop' => true, 'fingerprint' => $fingerprint]);
        self::assertSame(409, $this->statusCode());
        self::assertSame(['id', 'name', 'note'], $this->columnsOf($table));

        $this->call('POST', '/web/connections/'.$id.'/tables/'.$table.'/structure/apply', ['drop' => true, 'fingerprint' => $fingerprint, 'confirmed' => true]);
        self::assertSame(200, $this->statusCode());
        self::assertSame([], $this->columnsOf($table));
    }

    public function testAViewCannotBeEdited(): void
    {
        $this->seedAndLogin();
        $id = $this->saveTarget();

        [$detail, $fingerprint] = $this->load($id, 'v_order_totals');
        $body = $this->call('POST', '/web/connections/'.$id.'/tables/v_order_totals/structure/preview', ['draft' => self::draftOf($detail), 'fingerprint' => $fingerprint]);

        self::assertSame(422, $this->statusCode());
        self::assertSame('invalid_schema_change', $body['error'] ?? null);
    }

    public function testAnotherAccountCannotTouchTheConnection(): void
    {
        $this->seedAndLogin();
        $id = $this->saveTarget();
        $table = $this->scratch();
        [$detail, $fingerprint] = $this->load($id, $table);
        $this->call('POST', '/web/logout');
        $this->seedAndLogin('bob');

        $this->call('POST', '/web/connections/'.$id.'/tables/'.$table.'/structure/apply', ['draft' => $this->withNewColumn(self::draftOf($detail)), 'fingerprint' => $fingerprint, 'confirmed' => true]);

        self::assertSame(404, $this->statusCode());
        self::assertSame(['id', 'name', 'note'], $this->columnsOf($table));
    }

    public function testWorksOnMysqlToo(): void
    {
        $this->seedAndLogin();
        $id = $this->saveTarget('TARGET_MYSQL_URL');
        $mysql = (new DbalConnector())->connect(Engine::MySql, TargetDatabase::parameters('TARGET_MYSQL_URL'));
        $name = 'tdb_web_'.bin2hex(random_bytes(4));

        try {
            $mysql->executeStatement(\sprintf('CREATE TABLE `%s` (id int NOT NULL PRIMARY KEY, name varchar(40) NOT NULL) ENGINE=InnoDB', $name));
            [$detail, $fingerprint] = $this->load($id, $name);
            $preview = $this->call('POST', '/web/connections/'.$id.'/tables/'.$name.'/structure/preview', ['draft' => $this->withNewColumn(self::draftOf($detail)), 'fingerprint' => $fingerprint]);
            $applied = $this->call('POST', '/web/connections/'.$id.'/tables/'.$name.'/structure/apply', ['draft' => $this->withNewColumn(self::draftOf($detail)), 'fingerprint' => $fingerprint]);
            $columns = $mysql->fetchFirstColumn('SELECT column_name FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? ORDER BY ordinal_position', [$name]);
        } finally {
            $mysql->executeStatement(\sprintf('DROP TABLE IF EXISTS `%s`', $name));
            $mysql->close();
        }

        self::assertFalse($preview['transactional']);
        self::assertSame(200, $this->statusCode());
        self::assertSame(['id', 'name', 'qty'], $columns);
        self::assertArrayHasKey('table', $applied);
    }

    public function testRedisHasNoStructure(): void
    {
        $this->seedAndLogin();
        $id = $this->saveTarget('TARGET_REDIS_URL');

        $this->call('POST', '/web/connections/'.$id.'/tables/t/structure/preview', ['drop' => true, 'fingerprint' => str_repeat('a', 64)]);

        self::assertSame(501, $this->statusCode());
    }

    public function testPreviewWarnsAboutAnOpenTransactionThatHoldsTheTable(): void
    {
        $this->seedAndLogin();
        $id = $this->saveTarget();
        $table = $this->scratch();
        [$detail, $fingerprint] = $this->load($id, $table);
        $holder = (new DbalConnector())->connect(Engine::PostgreSql, TargetDatabase::parameters('TARGET_POSTGRES_URL'));

        try {
            $holder->executeStatement('BEGIN');
            $holder->fetchAllAssociative(\sprintf('SELECT * FROM "%s"', $table));
            $holderPid = (string) (\is_scalar($pid = $holder->fetchOne('SELECT pg_backend_pid()')) ? $pid : '');

            $body = $this->call('POST', '/web/connections/'.$id.'/tables/'.$table.'/structure/preview', ['draft' => $this->withNewColumn(self::draftOf($detail)), 'fingerprint' => $fingerprint]);
        } finally {
            $holder->executeStatement('ROLLBACK');
            $holder->close();
        }

        self::assertSame(200, $this->statusCode(), 'The warning never blocks the preview.');
        self::assertCount(1, self::arrayOf($body['statements']));
        $check = self::arrayOf($body['lockCheck']);
        self::assertSame('available', self::arrayOf($check['status'])['availability']);
        $holders = self::arrayOf($check['holders']);
        self::assertCount(1, $holders);
        $first = self::arrayOf($holders[0]);
        self::assertSame($holderPid, $first['session']);
        self::assertTrue($first['sleeping']);
        self::assertArrayNotHasKey('query', $first, 'No query text of another session.');
    }

    public function testPreviewOfAFreeTableSaysNobodyHoldsIt(): void
    {
        $this->seedAndLogin();
        $id = $this->saveTarget();
        $table = $this->scratch();
        [$detail, $fingerprint] = $this->load($id, $table);

        $body = $this->call('POST', '/web/connections/'.$id.'/tables/'.$table.'/structure/preview', ['draft' => $this->withNewColumn(self::draftOf($detail)), 'fingerprint' => $fingerprint]);

        $check = self::arrayOf($body['lockCheck']);
        self::assertSame('available', self::arrayOf($check['status'])['availability']);
        self::assertSame([], $check['holders']);
    }

    public function testATimedOutApplyExplainsWhichSessionHoldsTheTable(): void
    {
        $this->seedAndLogin();
        $id = $this->saveTarget();
        $table = $this->scratch();
        [$detail, $fingerprint] = $this->load($id, $table);
        $holder = (new DbalConnector())->connect(Engine::PostgreSql, TargetDatabase::parameters('TARGET_POSTGRES_URL'));

        try {
            $holder->executeStatement('BEGIN');
            $holder->fetchAllAssociative(\sprintf('SELECT * FROM "%s"', $table));
            $holderPid = (string) (\is_scalar($pid = $holder->fetchOne('SELECT pg_backend_pid()')) ? $pid : '');

            $body = $this->call('POST', '/web/connections/'.$id.'/tables/'.$table.'/structure/apply', ['draft' => $this->withNewColumn(self::draftOf($detail)), 'fingerprint' => $fingerprint, 'confirmed' => true]);
        } finally {
            $holder->executeStatement('ROLLBACK');
            $holder->close();
        }

        self::assertSame(409, $this->statusCode());
        self::assertSame('schema_change_failed', $body['error']);
        self::assertTrue($body['lockTimeout']);
        self::assertSame([], $body['applied']);
        $holders = self::arrayOf(self::arrayOf($body['lockCheck'])['holders']);
        self::assertSame($holderPid, self::arrayOf($holders[0])['session']);
    }
}
