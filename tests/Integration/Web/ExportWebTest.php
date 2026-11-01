<?php

declare(strict_types=1);

namespace App\Tests\Integration\Web;

use App\Tests\Integration\Driver\TargetDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

#[Group('integration')]
final class ExportWebTest extends WebApiTestCase
{
    private string $connectionId = '';

    /**
     * @return iterable<string, array{string}>
     */
    public static function targets(): iterable
    {
        yield from TargetDatabase::all();
    }

    private function prepare(string $envVar = 'TARGET_POSTGRES_URL'): void
    {
        $this->seedAndLogin();
        $parameters = TargetDatabase::parameters($envVar);
        $created = $this->call('POST', '/web/connections', ['name' => 'Target', 'engine' => TargetDatabase::engine($envVar)->value, 'host' => $parameters->host, 'port' => $parameters->port, 'database' => $parameters->database, 'username' => $parameters->username, 'password' => $parameters->password()]);
        $id = self::arrayOf($created['connection'])['id'];
        self::assertIsString($id);
        $this->connectionId = $id;
    }

    /**
     * @param array<string, mixed> $body
     */
    private function export(array $body): string
    {
        $this->client->request('POST', '/web/connections/'.$this->connectionId.'/export', server: ['CONTENT_TYPE' => 'application/json', 'HTTP_X_TERRILDB_REQUEST' => '1'], content: json_encode($body, \JSON_THROW_ON_ERROR));

        return (string) $this->client->getResponse()->getContent();
    }

    #[DataProvider('targets')]
    public function testExportsACsvFile(string $envVar): void
    {
        $this->prepare($envVar);

        $csv = $this->export(['sql' => 'select id, name from categories order by id', 'format' => 'csv', 'maxRows' => 3]);

        self::assertSame(200, $this->statusCode());
        $lines = explode("\r\n", rtrim($csv, "\r\n"));
        self::assertSame('id,name', $lines[0]);
        self::assertCount(4, $lines);
        self::assertSame('1,Maison', $lines[1]);
        $response = $this->client->getResponse();
        self::assertStringStartsWith('text/csv', (string) $response->headers->get('Content-Type'));
        self::assertMatchesRegularExpression('/^attachment; filename="?terrildb-export-\d{8}-\d{6}\.csv"?$/', (string) $response->headers->get('Content-Disposition'));
        self::assertSame('1', $response->headers->get('X-TerrilDB-Truncated'));
    }

    public function testExportsJson(): void
    {
        $this->prepare();

        $json = $this->export(['sql' => 'select id, name from categories order by id', 'format' => 'json', 'maxRows' => 2]);

        self::assertSame(200, $this->statusCode());
        $rows = json_decode($json, true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($rows);
        self::assertSame(['id' => 1, 'name' => 'Maison'], $rows[0]);
        self::assertStringStartsWith('application/json', (string) $this->client->getResponse()->headers->get('Content-Type'));
        self::assertMatchesRegularExpression('/\.json"?$/', (string) $this->client->getResponse()->headers->get('Content-Disposition'));
    }

    public function testAWholeResultIsNotFlaggedAsTruncated(): void
    {
        $this->prepare();

        $this->export(['sql' => 'select 1 as one', 'format' => 'csv']);

        self::assertFalse($this->client->getResponse()->headers->has('X-TerrilDB-Truncated'));
    }

    public function testCanAddAByteOrderMark(): void
    {
        $this->prepare();

        $csv = $this->export(['sql' => 'select 1 as one', 'format' => 'csv', 'bom' => true]);

        self::assertStringStartsWith("\xEF\xBB\xBF", $csv);
    }

    public function testNeutralizesFormulaCells(): void
    {
        $this->prepare();

        $csv = $this->export(['sql' => "select '=1+1' as cell", 'format' => 'csv']);

        self::assertSame("cell\r\n'=1+1\r\n", $csv);
    }

    public function testOnlyReadsAreExported(): void
    {
        $this->prepare();

        $body = json_decode($this->export(['sql' => 'delete from categories', 'format' => 'csv']), true, flags: \JSON_THROW_ON_ERROR);

        self::assertSame(403, $this->statusCode());
        self::assertIsArray($body);
        self::assertSame('query_refused', $body['error']);
    }

    public function testRefusesAnUnknownFormatOrBadLimits(): void
    {
        $this->prepare();

        $this->export(['sql' => 'select 1', 'format' => 'xlsx']);
        self::assertSame(400, $this->statusCode());
        $this->export(['sql' => 'select 1']);
        self::assertSame(400, $this->statusCode());
        $this->export(['sql' => 'select 1', 'format' => 'csv', 'maxRows' => 0]);
        self::assertSame(422, $this->statusCode());
        $this->export(['sql' => 'select 1', 'format' => 'csv', 'maxRows' => 100001]);
        self::assertSame(422, $this->statusCode());
    }

    public function testAnExportIsAuditedAsSuch(): void
    {
        $user = $this->seedAndLogin();
        $parameters = TargetDatabase::parameters('TARGET_POSTGRES_URL');
        $created = $this->call('POST', '/web/connections', ['name' => 'Target', 'engine' => 'postgresql', 'host' => $parameters->host, 'port' => $parameters->port, 'database' => $parameters->database, 'username' => $parameters->username, 'password' => $parameters->password()]);
        $id = self::arrayOf($created['connection'])['id'];
        self::assertIsString($id);
        $this->connectionId = $id;

        $this->export(['sql' => 'select 1 as one', 'format' => 'csv']);

        $event = $this->auditEvents($user, 'query')[0];
        self::assertSame('export', $event->getDetails()['purpose']);
        self::assertSame('csv', $event->getDetails()['format']);
    }

    public function testNeedsTheSessionKey(): void
    {
        $this->prepare();
        $this->client->getCookieJar()->expire('TERRILDB_UNLOCK');

        $this->export(['sql' => 'select 1', 'format' => 'csv']);

        self::assertSame(401, $this->statusCode());
    }
}
