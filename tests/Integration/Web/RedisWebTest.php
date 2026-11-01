<?php

declare(strict_types=1);

namespace App\Tests\Integration\Web;

use App\Tests\Integration\Driver\RedisTarget;
use App\Tests\Integration\Driver\TargetDatabase;
use PHPUnit\Framework\Attributes\Group;
use Predis\Client;

#[Group('integration')]
final class RedisWebTest extends WebApiTestCase
{
    private Client $seed;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed = RedisTarget::seeded();
    }

    protected function tearDown(): void
    {
        $this->seed->flushdb();
        parent::tearDown();
    }

    private function saveRedis(string $name = 'Cache'): string
    {
        $parameters = TargetDatabase::parameters(RedisTarget::ENV);
        $created = $this->call('POST', '/web/connections', [
            'name' => $name,
            'engine' => 'redis',
            'host' => $parameters->host,
            'port' => $parameters->port,
            'database' => $parameters->database,
            'username' => $parameters->username,
            'password' => $parameters->password(),
        ]);
        self::assertSame(201, $this->statusCode());
        $id = self::arrayOf($created['connection'])['id'];
        self::assertIsString($id);

        return $id;
    }

    public function testKeysRequireASession(): void
    {
        $this->call('GET', '/web/connections/00000000-0000-7000-8000-000000000000/keys');

        self::assertSame(401, $this->statusCode());
    }

    public function testListsTheKeysWithTheirTypeAndExpiry(): void
    {
        $this->seedAndLogin();
        $id = $this->saveRedis();

        $body = $this->call('GET', '/web/connections/'.$id.'/keys?count=1000');

        self::assertSame(200, $this->statusCode());
        self::assertSame(9, $body['total']);
        $keys = array_map(self::arrayOf(...), self::arrayOf($body['keys']));
        $byName = array_column($keys, null, 'name');
        self::assertSame(['name' => 'user:1', 'type' => 'string', 'ttl' => null], $byName['user:1']);
        self::assertSame('hash', $byName['profile:1']['type']);
        self::assertSame('zset', $byName['scores']['type']);
        self::assertIsInt($byName['session:abc']['ttl']);
    }

    public function testPagesThroughTheKeysWithACursor(): void
    {
        $this->seedAndLogin();
        $id = $this->saveRedis();
        $names = [];
        $cursor = null;

        for ($page = 0; $page < 100; ++$page) {
            $body = $this->call('GET', '/web/connections/'.$id.'/keys?pattern=user:*&count=1'.(null === $cursor ? '' : '&cursor='.rawurlencode($cursor)));
            self::assertSame(200, $this->statusCode());
            foreach (array_map(self::arrayOf(...), self::arrayOf($body['keys'])) as $key) {
                $names[] = $key['name'];
            }
            $cursor = \is_string($body['nextCursor']) ? $body['nextCursor'] : null;
            if (null === $cursor) {
                break;
            }
        }

        sort($names);
        self::assertSame(['user:1', 'user:2'], $names);
    }

    public function testAMalformedCursorOrCountIsRefused(): void
    {
        $this->seedAndLogin();
        $id = $this->saveRedis();

        $this->call('GET', '/web/connections/'.$id.'/keys?cursor=abc');
        self::assertSame(422, $this->statusCode());
        $this->call('GET', '/web/connections/'.$id.'/keys?count=0');
        self::assertSame(422, $this->statusCode());
        $this->call('GET', '/web/connections/'.$id.'/keys?count=100000');
        self::assertSame(422, $this->statusCode());
    }

    public function testReadsAKey(): void
    {
        $this->seedAndLogin();
        $id = $this->saveRedis();

        $body = $this->call('GET', '/web/connections/'.$id.'/key?name='.rawurlencode('profile:1'));

        self::assertSame(200, $this->statusCode());
        $key = self::arrayOf($body['key']);
        self::assertSame('profile:1', $key['name']);
        self::assertSame('hash', $key['type']);
        self::assertSame(['field', 'value'], $key['columns']);
        self::assertSame(2, $key['size']);
        self::assertCount(2, self::arrayOf($key['rows']));
        self::assertFalse($key['truncated']);
    }

    public function testAKeyWithUnicodeAndSlashesIsReadByItsName(): void
    {
        $this->seed->set('a/b?c=d&e', 'odd');
        $this->seedAndLogin();
        $id = $this->saveRedis();

        $unicode = $this->call('GET', '/web/connections/'.$id.'/key?name='.rawurlencode('日本語:キー'));
        $odd = $this->call('GET', '/web/connections/'.$id.'/key?name='.rawurlencode('a/b?c=d&e'));

        self::assertSame([['unicode']], self::arrayOf($unicode['key'])['rows']);
        self::assertSame([['odd']], self::arrayOf($odd['key'])['rows']);
    }

    public function testTheContentIsBoundedByTheLimit(): void
    {
        $this->seedAndLogin();
        $id = $this->saveRedis();

        $body = $this->call('GET', '/web/connections/'.$id.'/key?name=queue&limit=2');

        $key = self::arrayOf($body['key']);
        self::assertCount(2, self::arrayOf($key['rows']));
        self::assertTrue($key['truncated']);
        self::assertSame(3, $key['size']);
    }

    public function testAMissingKeyOrNameIsReported(): void
    {
        $this->seedAndLogin();
        $id = $this->saveRedis();

        $this->call('GET', '/web/connections/'.$id.'/key?name=nope');
        self::assertSame(404, $this->statusCode());
        $this->call('GET', '/web/connections/'.$id.'/key');
        self::assertSame(422, $this->statusCode());
    }

    public function testAnotherAccountsConnectionIsNotFound(): void
    {
        $this->seedAndLogin('alice');
        $id = $this->saveRedis();
        $this->call('POST', '/web/logout');
        $this->seedUser('bob');
        $this->login('bob');

        $this->call('GET', '/web/connections/'.$id.'/keys');

        self::assertSame(404, $this->statusCode());
    }

    public function testAnSqlConnectionHasNoKeys(): void
    {
        $this->seedAndLogin();
        $parameters = TargetDatabase::parameters('TARGET_POSTGRES_URL');
        $created = $this->call('POST', '/web/connections', ['name' => 'Pg', 'engine' => 'postgresql', 'host' => $parameters->host, 'port' => $parameters->port, 'database' => $parameters->database, 'username' => $parameters->username, 'password' => $parameters->password()]);
        $id = self::arrayOf($created['connection'])['id'];
        self::assertIsString($id);

        $body = $this->call('GET', '/web/connections/'.$id.'/keys');

        self::assertSame(501, $this->statusCode());
        self::assertSame('unsupported_operation', $body['error']);
    }

    public function testRunsAReadCommandThroughTheQueryEndpoint(): void
    {
        $this->seedAndLogin();
        $id = $this->saveRedis();

        $body = $this->call('POST', '/web/connections/'.$id.'/query', ['sql' => 'get user:1']);

        self::assertSame(200, $this->statusCode());
        self::assertSame(['value'], $body['columns']);
        self::assertSame([['Alice']], $body['rows']);
    }

    public function testWritesAreRefusedByDefault(): void
    {
        $this->seedAndLogin();
        $id = $this->saveRedis();

        $body = $this->call('POST', '/web/connections/'.$id.'/query', ['sql' => 'set user:1 hacked']);

        self::assertSame(403, $this->statusCode());
        self::assertSame('not_read_only', self::arrayOf($body)['reason']);
        self::assertSame('Alice', $this->seed->get('user:1'));
    }

    public function testWritesWorkInReadWriteModeAndFlushingAsksForConfirmation(): void
    {
        $this->seedAndLogin();
        $id = $this->saveRedis();

        $this->call('POST', '/web/connections/'.$id.'/query', ['sql' => 'set note hello', 'mode' => 'read_write']);
        self::assertSame(200, $this->statusCode());
        self::assertSame('hello', $this->seed->get('note'));

        $body = $this->call('POST', '/web/connections/'.$id.'/query', ['sql' => 'flushdb', 'mode' => 'read_write']);
        self::assertSame(409, $this->statusCode());
        self::assertSame('confirmation_required', $body['error']);
        self::assertSame(10, $this->seed->dbsize());

        $this->call('POST', '/web/connections/'.$id.'/query', ['sql' => 'flushdb', 'mode' => 'read_write', 'confirmed' => true]);
        self::assertSame(200, $this->statusCode());
        self::assertSame(0, $this->seed->dbsize());
    }

    public function testTheCommandIsInTheHistory(): void
    {
        $this->seedAndLogin();
        $id = $this->saveRedis();
        $this->call('POST', '/web/connections/'.$id.'/query', ['sql' => 'get user:2']);

        $history = $this->call('GET', '/web/connections/'.$id.'/history');

        self::assertSame(200, $this->statusCode());
        self::assertStringContainsString('get user:2', json_encode($history, \JSON_THROW_ON_ERROR));
    }

    public function testThereIsNoSchemaNoPlanNoDiagram(): void
    {
        $this->seedAndLogin();
        $id = $this->saveRedis();

        $this->call('GET', '/web/connections/'.$id.'/tables');
        self::assertSame(501, $this->statusCode());
        $plan = $this->call('POST', '/web/connections/'.$id.'/plan', ['sql' => 'get user:1']);
        self::assertSame(501, $this->statusCode());
        self::assertSame('plan_unavailable', $plan['error']);
        $this->call('GET', '/web/connections/'.$id.'/diagram');
        self::assertSame(501, $this->statusCode());
        $this->call('GET', '/web/connections/'.$id.'/audit');
        self::assertSame(501, $this->statusCode());
    }
}
