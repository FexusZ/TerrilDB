<?php

declare(strict_types=1);

namespace App\Tests\Integration\Web;

use App\Audit\AuditAction;
use App\Audit\AuditOutcome;
use App\Entity\User;
use App\Tests\Integration\Driver\TargetDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

#[Group('integration')]
final class QueryLintWebTest extends WebApiTestCase
{
    private User $user;
    private string $connectionId = '';

    /**
     * @return iterable<string, array{string}>
     */
    public static function targets(): iterable
    {
        yield from TargetDatabase::relational();
    }

    private function prepare(string $envVar): void
    {
        $this->user = $this->seedAndLogin();
        $parameters = TargetDatabase::parameters($envVar);
        $created = $this->call('POST', '/web/connections', ['name' => 'Target', 'engine' => TargetDatabase::engine($envVar)->value, 'host' => $parameters->host, 'port' => $parameters->port, 'database' => $parameters->database, 'username' => $parameters->username, 'password' => $parameters->password()]);
        $id = self::arrayOf($created['connection'])['id'];
        self::assertIsString($id);
        $this->connectionId = $id;
    }

    #[DataProvider('targets')]
    public function testReportsPatternsWithoutRunningAnything(string $envVar): void
    {
        $this->prepare($envVar);
        $sql = 'select * from orders where year(ordered_at) = 2026';

        $body = $this->call('POST', '/web/connections/'.$this->connectionId.'/lint', ['sql' => $sql]);

        self::assertSame(200, $this->statusCode());
        $findings = self::arrayOf($body['findings']);
        self::assertCount(2, $findings);
        $rules = array_map(static fn (mixed $f): mixed => self::arrayOf($f)['rule'], $findings);
        self::assertEqualsCanonicalizing(['function_on_column', 'select_star'], $rules);
        $first = self::arrayOf($findings[0]);
        self::assertContains($first['severity'], ['warning', 'info']);
        self::assertIsString($first['message']);
        self::assertArrayHasKey('suggestion', $first);

        $events = $this->auditEvents($this->user, 'query');
        self::assertCount(1, $events);
        self::assertSame(AuditAction::QueryLinted, $events[0]->getAction());
        self::assertSame(AuditOutcome::Success, $events[0]->getOutcome());
        self::assertSame($sql, $events[0]->getDetails()['sql']);
        self::assertSame(2, $events[0]->getDetails()['findings']);
    }

    public function testAWriteIsNeverLintedNorExecuted(): void
    {
        $this->prepare('TARGET_POSTGRES_URL');

        $body = $this->call('POST', '/web/connections/'.$this->connectionId.'/lint', ['sql' => "delete from categories where lower(name) = 'x'"]);

        self::assertSame(200, $this->statusCode());
        self::assertSame([], $body['findings']);
        self::assertSame(AuditAction::QueryLinted, $this->auditEvents($this->user, 'query')[0]->getAction());
    }

    public function testALongSelectIsAuditedTruncatedWithItsLength(): void
    {
        $this->prepare('TARGET_POSTGRES_URL');
        $sql = 'select '.str_repeat('id, ', 120).'id from orders';

        $this->call('POST', '/web/connections/'.$this->connectionId.'/lint', ['sql' => $sql]);

        $details = $this->auditEvents($this->user, 'query')[0]->getDetails();
        self::assertSame(mb_substr($sql, 0, 200), $details['sql']);
        self::assertSame(200, mb_strlen((string) $details['sql']));
        self::assertSame(mb_strlen($sql), $details['length']);
        self::assertTrue($details['truncated']);
    }

    public function testAShortSelectIsAuditedWhole(): void
    {
        $this->prepare('TARGET_POSTGRES_URL');

        $this->call('POST', '/web/connections/'.$this->connectionId.'/lint', ['sql' => 'select id from orders']);

        $details = $this->auditEvents($this->user, 'query')[0]->getDetails();
        self::assertSame('select id from orders', $details['sql']);
        self::assertFalse($details['truncated']);
    }

    public function testWhatIsNotASelectLeavesNoTextInTheAudit(): void
    {
        $this->prepare('TARGET_MYSQL_URL');
        $sql = "CREATE USER 'app'@'%' IDENTIFIED BY 'a-very-secret-phrase'";

        $body = $this->call('POST', '/web/connections/'.$this->connectionId.'/lint', ['sql' => $sql]);

        self::assertSame([], $body['findings']);
        $events = $this->auditEvents($this->user, 'query');
        self::assertCount(1, $events);
        $details = $events[0]->getDetails();
        self::assertArrayNotHasKey('sql', $details);
        self::assertStringNotContainsString('a-very-secret-phrase', json_encode($details, \JSON_THROW_ON_ERROR));
        self::assertSame(0, $details['findings']);
        self::assertSame(mb_strlen($sql), $details['length']);
    }

    public function testNeedsASessionAndTheRequestHeader(): void
    {
        $this->prepare('TARGET_POSTGRES_URL');
        $this->call('POST', '/web/logout');

        $this->call('POST', '/web/connections/'.$this->connectionId.'/lint', ['sql' => 'select 1']);

        self::assertSame(401, $this->statusCode());
    }
}
