<?php

declare(strict_types=1);

namespace App\Tests\Integration\Web;

use App\Tests\Integration\Driver\TargetDatabase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group;

#[Group('integration')]
final class ForbiddenHostsWebTest extends WebApiTestCase
{
    /**
     * @return array<string, mixed>
     */
    private function body(string $host, string $name = 'Server'): array
    {
        $parameters = TargetDatabase::parameters('TARGET_POSTGRES_URL');

        return ['name' => $name, 'engine' => 'postgresql', 'host' => $host, 'port' => $parameters->port, 'database' => $parameters->database, 'username' => $parameters->username, 'password' => $parameters->password()];
    }

    private function loginAsMember(): void
    {
        $this->seedAndLogin('alice');
        $this->call('POST', '/web/logout');
        $this->seedUser('bob');
        $this->login('bob');
    }

    public function testAMemberCannotSaveAConnectionToTheServerItself(): void
    {
        $this->loginAsMember();

        foreach (['127.0.0.1', 'localhost', '::1', 'db', '169.254.169.254'] as $host) {
            $body = $this->call('POST', '/web/connections', $this->body($host));
            self::assertSame(403, $this->statusCode(), $host);
            self::assertSame('forbidden_target', $body['error'], $host);
        }
        self::assertSame([], $this->call('GET', '/web/connections')['connections']);
    }

    public function testAMemberStillReachesAnotherMachine(): void
    {
        $this->loginAsMember();

        $this->call('POST', '/web/connections', $this->body(TargetDatabase::parameters('TARGET_POSTGRES_URL')->host));

        self::assertSame(201, $this->statusCode());
    }

    public function testAnAdministratorMayTargetTheServerItself(): void
    {
        $this->seedAndLogin('alice');

        $this->call('POST', '/web/connections', $this->body('127.0.0.1'));

        self::assertSame(201, $this->statusCode());
    }

    public function testAMemberCannotRedirectASavedConnectionToTheServerItself(): void
    {
        $this->loginAsMember();
        $created = $this->call('POST', '/web/connections', $this->body(TargetDatabase::parameters('TARGET_POSTGRES_URL')->host));
        $id = self::arrayOf($created['connection'])['id'];
        self::assertIsString($id);

        $body = $this->call('PUT', '/web/connections/'.$id, $this->body('127.0.0.1'));

        self::assertSame(403, $this->statusCode());
        self::assertSame('forbidden_target', $body['error']);
    }

    public function testAMemberCannotTryTheServerItselfEither(): void
    {
        $this->loginAsMember();

        $body = $this->call('POST', '/web/connections/test', $this->body('127.0.0.1'));

        self::assertSame(403, $this->statusCode());
        self::assertSame('forbidden_target', $body['error']);
    }

    public function testASavedConnectionToTheServerItselfIsNotOpenedForAMember(): void
    {
        $this->seedAndLogin('alice');
        $created = $this->call('POST', '/web/connections', $this->body('127.0.0.1'));
        $id = self::arrayOf($created['connection'])['id'];
        self::assertIsString($id);
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $entityManager->getConnection()->executeStatement("UPDATE app_user SET role = 'member'");

        $body = $this->call('GET', '/web/connections/'.$id.'/tables');

        self::assertSame(403, $this->statusCode());
        self::assertSame('forbidden_target', $body['error']);
    }

    /**
     * @return array<string, mixed>
     */
    private function createWithToken(string $host): array
    {
        $created = $this->call('POST', '/web/tokens', ['name' => 'Assistant', 'connectionIds' => [], 'createConnections' => true]);
        self::assertSame(201, $this->statusCode());
        self::assertIsString($created['token']);
        $this->client->getCookieJar()->clear();

        return $this->call('POST', '/api/v1/connections', $this->body($host), csrf: false, extraServer: ['HTTP_AUTHORIZATION' => 'Bearer '.$created['token']]);
    }

    public function testAMembersTokenCannotCreateAConnectionToTheServerItself(): void
    {
        $this->loginAsMember();

        $body = $this->createWithToken('127.0.0.1');

        self::assertSame(403, $this->statusCode());
        self::assertSame('forbidden_target', $body['error']);
    }

    public function testAnAdministratorsTokenMay(): void
    {
        $this->seedAndLogin('alice');

        $this->createWithToken('127.0.0.1');

        self::assertSame(201, $this->statusCode());
    }
}
