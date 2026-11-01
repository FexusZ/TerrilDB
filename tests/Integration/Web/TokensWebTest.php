<?php

declare(strict_types=1);

namespace App\Tests\Integration\Web;

use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group;

#[Group('integration')]
final class TokensWebTest extends WebApiTestCase
{
    private function createConnection(string $name = 'Production'): string
    {
        $created = $this->call('POST', '/web/connections', ['name' => $name, 'engine' => 'postgresql', 'host' => 'db.exemple.test', 'database' => 'shop', 'username' => 'lecteur', 'password' => 'hunter2-hunter2']);
        self::assertSame(201, $this->statusCode());
        $id = self::arrayOf($created['connection'])['id'];
        self::assertIsString($id);

        return $id;
    }

    public function testTokensRequireAnAuthenticatedSession(): void
    {
        $this->call('GET', '/web/tokens');

        self::assertSame(401, $this->statusCode());
    }

    public function testCreatesATokenAndShowsItOnce(): void
    {
        $this->seedAndLogin();
        $connection = $this->createConnection();

        $created = $this->call('POST', '/web/tokens', ['name' => 'Assistant', 'connectionIds' => [$connection], 'lifetimeDays' => 7]);

        self::assertSame(201, $this->statusCode());
        self::assertIsString($created['token']);
        self::assertMatchesRegularExpression('/^tdb_[0-9a-f]{32}_[A-Za-z0-9_-]{43}$/D', $created['token']);
        $info = self::arrayOf($created['tokenInfo']);
        self::assertSame('Assistant', $info['name']);
        self::assertSame([$connection], $info['connectionIds']);
        self::assertNull($info['lastUsedAt']);
        self::assertArrayNotHasKey('token', $info);

        $list = $this->call('GET', '/web/tokens');
        self::assertSame(200, $this->statusCode());
        self::assertCount(1, self::arrayOf($list['tokens']));
        self::assertStringNotContainsString($created['token'], json_encode($list, \JSON_THROW_ON_ERROR));
        self::assertStringNotContainsString(substr($created['token'], -43), json_encode($list, \JSON_THROW_ON_ERROR));
    }

    public function testTheLifetimeDefaultsToThirtyDays(): void
    {
        $this->seedAndLogin();
        $connection = $this->createConnection();

        $created = $this->call('POST', '/web/tokens', ['name' => 'Assistant', 'connectionIds' => [$connection]]);

        $info = self::arrayOf($created['tokenInfo']);
        self::assertIsString($info['expiresAt']);
        self::assertIsString($info['createdAt']);
        self::assertSame(30, (new \DateTimeImmutable($info['expiresAt']))->diff(new \DateTimeImmutable($info['createdAt']))->days);
    }

    public function testNothingSecretIsStoredInTheInternalDatabase(): void
    {
        $this->seedAndLogin();
        $connection = $this->createConnection();
        $created = $this->call('POST', '/web/tokens', ['name' => 'Assistant', 'connectionIds' => [$connection]]);
        self::assertIsString($created['token']);

        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $row = json_encode($entityManager->getConnection()->fetchAllAssociative('SELECT * FROM api_token'), \JSON_THROW_ON_ERROR);

        self::assertStringNotContainsString(substr($created['token'], -43), $row);
    }

    public function testRefusesInvalidDefinitions(): void
    {
        $this->seedAndLogin();
        $connection = $this->createConnection();

        $none = $this->call('POST', '/web/tokens', ['name' => 'X', 'connectionIds' => []]);
        self::assertSame(422, $this->statusCode());
        self::assertSame('invalid_input', $none['error']);

        $this->call('POST', '/web/tokens', ['name' => 'X', 'connectionIds' => [$connection], 'lifetimeDays' => 0]);
        self::assertSame(422, $this->statusCode());
        $this->call('POST', '/web/tokens', ['name' => 'X', 'connectionIds' => [$connection], 'lifetimeDays' => 1000]);
        self::assertSame(422, $this->statusCode());
        $this->call('POST', '/web/tokens', ['name' => '', 'connectionIds' => [$connection]]);
        self::assertSame(422, $this->statusCode());
        $this->call('POST', '/web/tokens', ['name' => 'X', 'connectionIds' => ['not-a-uuid']]);
        self::assertSame(404, $this->statusCode());

        $malformed = $this->call('POST', '/web/tokens', ['name' => 'X', 'connectionIds' => 'abc']);
        self::assertSame(400, $this->statusCode());
        self::assertSame('bad_request', $malformed['error']);
    }

    public function testRefusesAnotherAccountsConnection(): void
    {
        $this->seedAndLogin('alice');
        $theirs = $this->createConnection();
        $this->call('POST', '/web/logout');
        $this->seedUser('bob');
        $this->login('bob');

        $body = $this->call('POST', '/web/tokens', ['name' => 'Sneaky', 'connectionIds' => [$theirs]]);

        self::assertSame(404, $this->statusCode());
        self::assertSame('not_found', $body['error']);
    }

    public function testRevokesAToken(): void
    {
        $this->seedAndLogin();
        $connection = $this->createConnection();
        $created = $this->call('POST', '/web/tokens', ['name' => 'Assistant', 'connectionIds' => [$connection]]);
        $id = self::arrayOf($created['tokenInfo'])['id'];
        self::assertIsString($id);

        $this->call('DELETE', '/web/tokens/'.$id);
        self::assertSame(204, $this->statusCode());

        self::assertSame([], $this->call('GET', '/web/tokens')['tokens']);
        $this->call('DELETE', '/web/tokens/'.$id);
        self::assertSame(404, $this->statusCode());
    }

    public function testAnotherAccountsTokenIsInvisible(): void
    {
        $this->seedAndLogin('alice');
        $connection = $this->createConnection();
        $created = $this->call('POST', '/web/tokens', ['name' => 'Assistant', 'connectionIds' => [$connection]]);
        $id = self::arrayOf($created['tokenInfo'])['id'];
        self::assertIsString($id);
        $this->call('POST', '/web/logout');
        $this->seedUser('bob');
        $this->login('bob');

        $this->call('DELETE', '/web/tokens/'.$id);

        self::assertSame(404, $this->statusCode());
        self::assertSame([], $this->call('GET', '/web/tokens')['tokens']);
    }

    public function testCreatingATokenNeedsTheSessionKey(): void
    {
        $this->seedAndLogin();
        $connection = $this->createConnection();
        $this->client->getCookieJar()->expire('TERRILDB_UNLOCK');

        $body = $this->call('POST', '/web/tokens', ['name' => 'Assistant', 'connectionIds' => [$connection]]);

        self::assertSame(401, $this->statusCode());
        self::assertSame('locked', $body['error']);
    }
}
