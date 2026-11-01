<?php

declare(strict_types=1);

namespace App\Tests\Integration\Web;

use App\Driver\DbalConnector;
use App\Tests\Integration\Driver\TargetDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

#[Group('integration')]
final class SchemaAuditWebTest extends WebApiTestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function targets(): iterable
    {
        yield from TargetDatabase::all();
    }

    private function saveTarget(string $envVar): string
    {
        $parameters = TargetDatabase::parameters($envVar);
        $created = $this->call('POST', '/web/connections', ['name' => 'Target', 'engine' => TargetDatabase::engine($envVar)->value, 'host' => $parameters->host, 'port' => $parameters->port, 'database' => $parameters->database, 'username' => $parameters->username, 'password' => $parameters->password()]);
        $id = self::arrayOf($created['connection'])['id'];
        self::assertIsString($id);

        return $id;
    }

    public function testRequiresASession(): void
    {
        $this->call('GET', '/web/connections/00000000-0000-7000-8000-000000000000/audit');

        self::assertSame(401, $this->statusCode());
    }

    #[DataProvider('targets')]
    public function testTheSampleSchemaIsClean(string $envVar): void
    {
        $this->seedAndLogin();
        $id = $this->saveTarget($envVar);

        $body = $this->call('GET', '/web/connections/'.$id.'/audit');

        self::assertSame(200, $this->statusCode());
        self::assertSame(5, $body['tablesAudited']);
        self::assertFalse($body['truncated']);
        self::assertSame([], $body['findings']);
    }

    public function testFindsWhatIsWrongInAnActualSchema(): void
    {
        $this->seedAndLogin();
        $id = $this->saveTarget('TARGET_POSTGRES_URL');
        $connection = (new DbalConnector())->connect(TargetDatabase::engine('TARGET_POSTGRES_URL'), TargetDatabase::parameters('TARGET_POSTGRES_URL'));

        try {
            $connection->executeStatement('CREATE TABLE audit_probe (note text, category_id integer REFERENCES categories (id))');
            $connection->executeStatement('CREATE TABLE audit_probe_ix (id integer PRIMARY KEY, a integer)');
            $connection->executeStatement('CREATE INDEX audit_probe_ix_a ON audit_probe_ix (a)');
            $connection->executeStatement('CREATE INDEX audit_probe_ix_ab ON audit_probe_ix (a, id)');

            $body = $this->call('GET', '/web/connections/'.$id.'/audit');
        } finally {
            $connection->executeStatement('DROP TABLE IF EXISTS audit_probe, audit_probe_ix');
            $connection->close();
        }

        self::assertSame(200, $this->statusCode());
        $findings = array_map(self::arrayOf(...), self::arrayOf($body['findings']));
        $summary = array_map(static fn (array $f): string => \sprintf('%s:%s', \is_string($f['rule']) ? $f['rule'] : '', \is_string($f['table']) ? $f['table'] : ''), $findings);
        self::assertContains('missing_primary_key:audit_probe', $summary);
        self::assertContains('foreign_key_without_index:audit_probe', $summary);
        self::assertContains('redundant_index:audit_probe_ix', $summary);
        $fk = $findings[array_search('foreign_key_without_index:audit_probe', $summary, true)];
        self::assertSame('warning', $fk['severity']);
        self::assertSame(['category_id'], $fk['columns']);
        self::assertSame('CREATE INDEX idx_audit_probe_category_id ON "audit_probe" ("category_id");', $fk['suggestion']);
        $redundant = $findings[array_search('redundant_index:audit_probe_ix', $summary, true)];
        self::assertSame('audit_probe_ix_a', $redundant['index']);
        self::assertSame('audit_probe_ix_ab', $redundant['coveredBy']);
        self::assertSame(['rule', 'severity', 'table', 'columns', 'index', 'coveredBy', 'suggestion'], array_keys($fk));
    }

    public function testAnotherAccountsConnectionIsNotFound(): void
    {
        $this->seedAndLogin('alice');
        $id = $this->saveTarget('TARGET_POSTGRES_URL');
        $this->call('POST', '/web/logout');
        $this->seedUser('bob');
        $this->login('bob');

        $this->call('GET', '/web/connections/'.$id.'/audit');

        self::assertSame(404, $this->statusCode());
    }
}
