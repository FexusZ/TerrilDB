<?php

declare(strict_types=1);

namespace App\Tests\Integration\Web;

use App\Tests\Integration\Driver\TargetDatabase;
use PHPUnit\Framework\Attributes\Group;

#[Group('integration')]
final class ActivityWebTest extends WebApiTestCase
{
    private function createConnection(string $name = 'Target'): string
    {
        $parameters = TargetDatabase::parameters('TARGET_POSTGRES_URL');
        $created = $this->call('POST', '/web/connections', ['name' => $name, 'engine' => 'postgresql', 'host' => $parameters->host, 'port' => $parameters->port, 'database' => $parameters->database, 'username' => $parameters->username, 'password' => $parameters->password()]);
        $id = self::arrayOf($created['connection'])['id'];
        self::assertIsString($id);

        return $id;
    }

    /**
     * @return list<array<array-key, mixed>>
     */
    private function events(string $query = ''): array
    {
        $body = $this->call('GET', '/web/activity'.$query);
        self::assertSame(200, $this->statusCode());

        return array_values(array_map(self::arrayOf(...), self::arrayOf($body['events'])));
    }

    public function testActivityRequiresAnAuthenticatedSession(): void
    {
        $this->call('GET', '/web/activity');

        self::assertSame(401, $this->statusCode());
    }

    public function testListsTheAccountsOwnEventsNewestFirst(): void
    {
        $this->seedAndLogin();
        $this->createConnection();

        $events = $this->events();

        self::assertSame(['connection.created', 'login.success'], array_column($events, 'action'));
        $first = $events[0];
        self::assertSame(['id', 'occurredAt', 'action', 'category', 'outcome', 'actor', 'ip', 'subjectId', 'tokenId', 'details'], array_keys($first));
        self::assertSame('connection', $first['category']);
        self::assertSame('success', $first['outcome']);
        self::assertSame('web', $first['actor']);
        self::assertIsString($first['occurredAt']);
    }

    public function testNeverShowsAnotherAccountsEvents(): void
    {
        $this->seedAndLogin('alice');
        $this->createConnection('Alices');
        $this->call('POST', '/web/logout');
        $this->seedUser('bob');
        $this->login('bob');

        self::assertSame(['login.success'], array_column($this->events(), 'action'));
    }

    public function testPagesWithACursor(): void
    {
        $this->seedAndLogin();
        for ($i = 0; $i < 4; ++$i) {
            $this->createConnection('C'.$i);
        }

        $page = $this->call('GET', '/web/activity?limit=2');
        $first = array_values(array_map(self::arrayOf(...), self::arrayOf($page['events'])));
        self::assertCount(2, $first);
        self::assertIsString($page['next']);
        self::assertSame($first[1]['id'], $page['next']);

        self::assertIsString($page['next']);
        $second = $this->call('GET', '/web/activity?limit=2&before='.$page['next']);
        $secondEvents = array_values(array_map(self::arrayOf(...), self::arrayOf($second['events'])));
        self::assertCount(2, $secondEvents);
        self::assertNotSame($first[0]['id'], $secondEvents[0]['id']);

        self::assertIsString($second['next']);
        $third = $this->call('GET', '/web/activity?limit=2&before='.$second['next']);
        self::assertCount(1, self::arrayOf($third['events']));
        self::assertNull($third['next']);
    }

    public function testFiltersByCategory(): void
    {
        $this->seedAndLogin();
        $this->createConnection();

        self::assertSame(['connection.created'], array_column($this->events('?category=connection'), 'action'));
        self::assertSame([], $this->events('?category=query'));
    }

    public function testRefusesBadParameters(): void
    {
        $this->seedAndLogin();

        foreach (['?category=nonsense', '?before=not-a-uuid', '?limit=0', '?limit=9999', '?limit=abc'] as $query) {
            $body = $this->call('GET', '/web/activity'.$query);
            self::assertSame(400, $this->statusCode(), $query);
            self::assertSame('bad_request', $body['error']);
        }
    }

    public function testQueryHistoryOfAConnection(): void
    {
        $this->seedAndLogin();
        $connection = $this->createConnection();
        $path = '/web/connections/'.$connection;
        $this->call('POST', $path.'/query', ['sql' => 'select 1']);
        $this->call('POST', $path.'/query', ['sql' => 'drop table categories']);
        $this->call('POST', $path.'/query', ['sql' => 'select * from nope']);

        $body = $this->call('GET', $path.'/history');

        self::assertSame(200, $this->statusCode());
        $entries = array_values(array_map(self::arrayOf(...), self::arrayOf($body['entries'])));
        self::assertSame(['select * from nope', 'drop table categories', 'select 1'], array_column($entries, 'sql'));
        self::assertSame(['failed', 'refused', 'success'], array_column($entries, 'outcome'));
        self::assertSame('web', $entries[2]['actor']);
        self::assertSame(1, $entries[2]['rowCount']);
        self::assertIsInt($entries[2]['durationMs']);
        self::assertSame('not_read_only', $entries[1]['reason']);
        self::assertSame(['id', 'occurredAt', 'actor', 'outcome', 'sql', 'mode', 'rowCount', 'durationMs', 'reason'], array_keys($entries[0]));
    }

    public function testHistoryIsPerConnection(): void
    {
        $this->seedAndLogin();
        $first = $this->createConnection('First');
        $second = $this->createConnection('Second');
        $this->call('POST', '/web/connections/'.$first.'/query', ['sql' => 'select 1']);
        $this->call('POST', '/web/connections/'.$second.'/query', ['sql' => 'select 2']);

        $body = $this->call('GET', '/web/connections/'.$second.'/history');

        self::assertSame(['select 2'], array_column(array_map(self::arrayOf(...), self::arrayOf($body['entries'])), 'sql'));
    }

    public function testHistoryOfSomeoneElsesConnectionIsNotFound(): void
    {
        $this->seedAndLogin('alice');
        $theirs = $this->createConnection();
        $this->call('POST', '/web/logout');
        $this->seedUser('bob');
        $this->login('bob');

        $this->call('GET', '/web/connections/'.$theirs.'/history');

        self::assertSame(404, $this->statusCode());
    }

    public function testHistoryPaginates(): void
    {
        $this->seedAndLogin();
        $connection = $this->createConnection();
        foreach ([1, 2, 3] as $n) {
            $this->call('POST', '/web/connections/'.$connection.'/query', ['sql' => 'select '.$n]);
        }

        $page = $this->call('GET', '/web/connections/'.$connection.'/history?limit=2');
        self::assertCount(2, self::arrayOf($page['entries']));
        self::assertIsString($page['next']);

        $rest = $this->call('GET', '/web/connections/'.$connection.'/history?limit=2&before='.$page['next']);
        self::assertSame(['select 1'], array_column(array_map(self::arrayOf(...), self::arrayOf($rest['entries'])), 'sql'));
        self::assertNull($rest['next']);
    }
}
