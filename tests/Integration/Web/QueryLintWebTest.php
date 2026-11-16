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

    /**
     * @param array<string, mixed> $details
     */
    private static function sqlOf(array $details): string
    {
        self::assertIsString($details['sql'] ?? null);

        return $details['sql'];
    }

    #[DataProvider('targets')]
    public function testReportsPatternsWithoutRunningAnything(string $envVar): void
    {
        $this->prepare($envVar);
        $sql = "select * from orders where lower(status) = 'x'";

        $body = $this->call('POST', '/web/connections/'.$this->connectionId.'/lint', ['sql' => $sql]);

        self::assertSame(200, $this->statusCode());
        $findings = self::arrayOf($body['findings']);
        self::assertCount(2, $findings);
        $rules = array_map(static fn (mixed $f): mixed => self::arrayOf($f)['rule'], $findings);
        self::assertEqualsCanonicalizing(['function_on_column', 'select_star'], $rules);
        $first = self::arrayOf($findings[0]);
        $function = self::arrayOf($findings[array_search('function_on_column', $rules, true)]);
        self::assertIsString($function['message']);
        self::assertStringContainsString('idx_orders_status', $function['message']);
        self::assertContains($first['severity'], ['warning', 'info']);
        self::assertIsString($first['message']);
        self::assertArrayHasKey('suggestion', $first);

        $events = $this->auditEvents($this->user, 'query');
        self::assertCount(1, $events);
        self::assertSame(AuditAction::QueryLinted, $events[0]->getAction());
        self::assertSame(AuditOutcome::Success, $events[0]->getOutcome());
        self::assertSame('select * from orders where lower(status) = ?', $events[0]->getDetails()['sql']);
        self::assertSame(2, $events[0]->getDetails()['findings']);
    }

    /**
     * @return list<string>
     */
    private function rulesFor(string $sql): array
    {
        $body = $this->call('POST', '/web/connections/'.$this->connectionId.'/lint', ['sql' => $sql]);
        self::assertSame(200, $this->statusCode());

        return array_map(static fn (mixed $f): string => self::stringOf(self::arrayOf($f)['rule']), self::listOf($body['findings']));
    }

    #[DataProvider('targets')]
    public function testAColumnWithoutALeadingIndexGetsNoSargabilityAdvice(string $envVar): void
    {
        $this->prepare($envVar);

        // ordered_at n'est que le second champ de idx_orders_customer_date : l'index ne servirait de toute façon pas.
        self::assertSame([], $this->rulesFor('select id from orders where year(ordered_at) = 2026'));
        self::assertSame(['function_on_column'], $this->rulesFor("select id from orders where lower(status) = 'x'"));
        self::assertSame([], $this->rulesFor("select id from orders where lower(nope) = 'x'"));
    }

    #[DataProvider('targets')]
    public function testTheTypeOfAColumnIsReadFromTheTarget(string $envVar): void
    {
        $this->prepare($envVar);

        self::assertSame(['type_mismatch'], $this->rulesFor('select id from orders where status = 5'));
        self::assertSame([], $this->rulesFor('select id from orders where customer_id = 5'));
        self::assertSame(['or_on_columns'], $this->rulesFor("select id from orders where status = 'new' or ordered_at < '2020-01-01'"));
        self::assertSame([], $this->rulesFor("select id from orders where status = 'new' or customer_id = 3"));
        self::assertSame(['distinct_join'], $this->rulesFor('select distinct o.id from orders o join order_items i on i.order_id = o.id'));
    }

    public function testAnUnknownTableKeepsTheConditionalText(): void
    {
        $this->prepare('TARGET_POSTGRES_URL');

        $body = $this->call('POST', '/web/connections/'.$this->connectionId.'/lint', ['sql' => 'select id from no_such_table where year(d) = 2026']);

        self::assertSame(200, $this->statusCode());
        $finding = self::arrayOf(self::listOf($body['findings'])[0]);
        self::assertSame('function_on_column', $finding['rule']);
        self::assertStringContainsString('un index sur cette colonne ne peut pas servir', self::stringOf($finding['message']));
    }

    public function testAWriteIsNeverLintedNorExecuted(): void
    {
        $this->prepare('TARGET_POSTGRES_URL');

        $body = $this->call('POST', '/web/connections/'.$this->connectionId.'/lint', ['sql' => "delete from categories where lower(name) = 'x'"]);

        self::assertSame(200, $this->statusCode());
        self::assertSame([], $body['findings']);
        self::assertSame(AuditAction::QueryLinted, $this->auditEvents($this->user, 'query')[0]->getAction());
    }

    public function testALongSelectIsAuditedMaskedThenTruncatedWithItsLength(): void
    {
        $this->prepare('TARGET_POSTGRES_URL');
        $sql = 'select '.str_repeat('id, ', 120).'id from orders';

        $this->call('POST', '/web/connections/'.$this->connectionId.'/lint', ['sql' => $sql]);

        $details = $this->auditEvents($this->user, 'query')[0]->getDetails();
        self::assertSame(200, mb_strlen(self::sqlOf($details)));
        self::assertStringStartsWith('select id, id, id,', self::sqlOf($details));
        self::assertStringEndsWith('…', self::sqlOf($details));
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

    #[DataProvider('targets')]
    public function testTheValuesOfASelectNeverReachTheAudit(string $envVar): void
    {
        $this->prepare($envVar);
        $sql = "select id from customers where email = 'jean.dupont@example.org' and phone = '0612345678' and id = 4242 -- jean.dupont@example.org";

        $body = $this->call('POST', '/web/connections/'.$this->connectionId.'/lint', ['sql' => $sql]);

        self::assertSame(200, $this->statusCode());
        self::assertIsArray($body['findings']);
        $details = $this->auditEvents($this->user, 'query')[0]->getDetails();
        self::assertSame('select id from customers where email = ? and phone = ? and id = ?', trim((string) preg_replace('/\s+/', ' ', self::sqlOf($details))));
        $all = json_encode($details, \JSON_THROW_ON_ERROR);
        foreach (['jean.dupont', 'example.org', '0612345678', '4242'] as $value) {
            self::assertStringNotContainsString($value, $all);
        }
        self::assertSame(mb_strlen($sql), $details['length']);
    }

    public function testAValueBeyondTheCutIsMaskedBeforeTheCutNotAfter(): void
    {
        $this->prepare('TARGET_POSTGRES_URL');
        $sql = 'select '.str_repeat('a', 180)." from t where email = 'zz-secret-value-zz'";

        $this->call('POST', '/web/connections/'.$this->connectionId.'/lint', ['sql' => $sql]);

        $details = $this->auditEvents($this->user, 'query')[0]->getDetails();
        self::assertStringNotContainsString('zz-secret', json_encode($details, \JSON_THROW_ON_ERROR));
        self::assertStringNotContainsString("'", self::sqlOf($details));
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
