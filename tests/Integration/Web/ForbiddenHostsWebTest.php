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

    /**
     * @return list<array<string, mixed>>
     */
    private function refusals(): array
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);

        return $entityManager->getConnection()->fetchAllAssociative("SELECT actor, outcome, token_id, details FROM audit_event WHERE action = 'target.forbidden' ORDER BY occurred_at DESC");
    }

    public function testARefusedAddressLeavesATraceInTheAuditLog(): void
    {
        $this->loginAsMember();

        $this->call('POST', '/web/connections', $this->body('127.0.0.1'));

        $rows = $this->refusals();
        self::assertCount(1, $rows);
        self::assertSame('web', $rows[0]['actor']);
        self::assertSame('refused', $rows[0]['outcome']);
        self::assertNull($rows[0]['token_id']);
        self::assertIsString($rows[0]['details']);
        $details = json_decode($rows[0]['details'], true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($details);
        self::assertSame('127.0.0.1', $details['host']);
        self::assertStringNotContainsString(TargetDatabase::parameters('TARGET_POSTGRES_URL')->password(), (string) $rows[0]['details']);
    }

    public function testAnAllowedAddressLeavesNoSuchTrace(): void
    {
        $this->loginAsMember();

        $this->call('POST', '/web/connections', $this->body(TargetDatabase::parameters('TARGET_POSTGRES_URL')->host));

        self::assertSame([], $this->refusals());
    }

    public function testAnAdministratorAllowedThroughLeavesNoTrace(): void
    {
        $this->seedAndLogin('alice');

        $this->call('POST', '/web/connections', $this->body('127.0.0.1'));

        self::assertSame([], $this->refusals());
    }

    public function testARefusalThroughATokenIsAttributedToTheToken(): void
    {
        $this->loginAsMember();
        $this->createWithToken('127.0.0.1');

        $rows = $this->refusals();
        self::assertCount(1, $rows);
        self::assertSame('api', $rows[0]['actor']);
        self::assertNotNull($rows[0]['token_id']);
    }

    public function testOpeningASavedConnectionToTheServerItselfIsAlsoRecorded(): void
    {
        $this->seedAndLogin('alice');
        $created = $this->call('POST', '/web/connections', $this->body('127.0.0.1'));
        $id = self::arrayOf($created['connection'])['id'];
        self::assertIsString($id);
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $entityManager->getConnection()->executeStatement("UPDATE app_user SET role = 'member'");

        $this->call('GET', '/web/connections/'.$id.'/tables');

        self::assertCount(1, $this->refusals());
    }

    public function testRefusedAttemptsAlsoUseTheCreationQuota(): void
    {
        $this->loginAsMember();
        $created = $this->call('POST', '/web/tokens', ['name' => 'Assistant', 'connectionIds' => [], 'createConnections' => true]);
        self::assertIsString($created['token']);
        $this->client->getCookieJar()->clear();
        $server = ['HTTP_AUTHORIZATION' => 'Bearer '.$created['token']];

        for ($i = 1; $i <= 20; ++$i) {
            $this->call('POST', '/api/v1/connections', $this->body('127.0.0.1', 'Probe '.$i), csrf: false, extraServer: $server);
            self::assertSame(403, $this->statusCode(), 'attempt '.$i);
        }
        $this->call('POST', '/api/v1/connections', $this->body(TargetDatabase::parameters('TARGET_POSTGRES_URL')->host), csrf: false, extraServer: $server);

        self::assertSame(429, $this->statusCode());
    }
}
