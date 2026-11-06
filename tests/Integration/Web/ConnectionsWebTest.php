<?php

declare(strict_types=1);

namespace App\Tests\Integration\Web;

use App\Tests\Integration\Driver\TargetDatabase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

#[Group('integration')]
final class ConnectionsWebTest extends WebApiTestCase
{
    /**
     * @return array<string, mixed>
     */
    private function body(string $name = 'Production', string $engine = 'postgresql', string $password = 'hunter2-hunter2'): array
    {
        return ['name' => $name, 'engine' => $engine, 'host' => 'db.exemple.test', 'port' => 5432, 'database' => 'shop', 'username' => 'lecteur', 'password' => $password];
    }

    /**
     * @return array<string, mixed>
     */
    private function targetBody(string $envVar, string $name = 'Target'): array
    {
        $parameters = TargetDatabase::parameters($envVar);

        return [
            'name' => $name,
            'engine' => TargetDatabase::engine($envVar)->value,
            'host' => $parameters->host,
            'port' => $parameters->port,
            'database' => $parameters->database,
            'username' => $parameters->username,
            'password' => $parameters->password(),
        ];
    }

    private function create(string $name = 'Production'): string
    {
        $created = $this->call('POST', '/web/connections', $this->body($name));
        self::assertSame(201, $this->statusCode());
        self::assertIsArray($created['connection']);
        self::assertIsString($created['connection']['id']);

        return $created['connection']['id'];
    }

    public function testConnectionsRequireAnAuthenticatedSession(): void
    {
        $this->call('GET', '/web/connections');

        self::assertSame(401, $this->statusCode());
    }

    public function testACreatedConnectionIsListedWithoutItsParameters(): void
    {
        $this->seedAndLogin();

        $created = $this->call('POST', '/web/connections', $this->body());
        self::assertSame(201, $this->statusCode());
        self::assertIsArray($created['connection']);
        self::assertSame('Production', $created['connection']['name']);
        self::assertSame('postgresql', $created['connection']['engine']);
        self::assertSame(['id', 'name', 'engine', 'color', 'createdAt', 'updatedAt'], array_keys($created['connection']));

        $list = $this->call('GET', '/web/connections');
        self::assertSame(200, $this->statusCode());
        self::assertIsArray($list['connections']);
        self::assertCount(1, $list['connections']);
        $json = json_encode($list, \JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('db.exemple.test', $json);
        self::assertStringNotContainsString('hunter2', $json);
    }

    public function testNothingReadableIsStoredInTheInternalDatabase(): void
    {
        $this->seedAndLogin();
        $this->create();

        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $row = json_encode($entityManager->getConnection()->fetchAllAssociative('SELECT * FROM database_connection'), \JSON_THROW_ON_ERROR);

        foreach (['db.exemple.test', 'lecteur', 'hunter2', 'shop'] as $secret) {
            self::assertStringNotContainsString($secret, $row);
        }
    }

    public function testShowGivesTheParametersExceptThePassword(): void
    {
        $this->seedAndLogin();
        $id = $this->create();

        $shown = $this->call('GET', '/web/connections/'.$id);

        self::assertSame(200, $this->statusCode());
        self::assertSame(
            ['host' => 'db.exemple.test', 'port' => 5432, 'database' => 'shop', 'username' => 'lecteur', 'hasPassword' => true],
            $shown['parameters'],
        );
        self::assertStringNotContainsString('hunter2', json_encode($shown, \JSON_THROW_ON_ERROR));
    }

    public function testThePortDefaultsToTheEnginesOne(): void
    {
        $this->seedAndLogin();
        $body = $this->body('Blog', 'mysql');
        unset($body['port']);

        $created = $this->call('POST', '/web/connections', $body);
        self::assertSame(201, $this->statusCode());
        self::assertIsArray($created['connection']);
        self::assertIsString($created['connection']['id']);
        $shown = $this->call('GET', '/web/connections/'.$created['connection']['id']);
        self::assertIsArray($shown['parameters']);
        self::assertSame(3306, $shown['parameters']['port']);
    }

    public function testAnUpdateWithoutPasswordKeepsThePasswordThatWasSaved(): void
    {
        $this->seedAndLogin();
        $created = $this->call('POST', '/web/connections', $this->targetBody('TARGET_POSTGRES_URL', 'Before'));
        self::assertIsArray($created['connection']);
        $id = $created['connection']['id'];
        self::assertIsString($id);

        $body = $this->targetBody('TARGET_POSTGRES_URL', 'After');
        unset($body['password']);
        $updated = $this->call('PUT', '/web/connections/'.$id, $body);
        self::assertSame(200, $this->statusCode());
        self::assertIsArray($updated['connection']);
        self::assertSame('After', $updated['connection']['name']);

        $this->call('POST', '/web/connections/'.$id.'/test');
        self::assertSame(200, $this->statusCode(), 'le mot de passe enregistré a été conservé');
    }

    public function testAnUpdateWithANewPasswordReplacesIt(): void
    {
        $this->seedAndLogin();
        $created = $this->call('POST', '/web/connections', $this->targetBody('TARGET_POSTGRES_URL'));
        self::assertIsArray($created['connection']);
        $id = $created['connection']['id'];
        self::assertIsString($id);

        $body = $this->targetBody('TARGET_POSTGRES_URL');
        $body['password'] = 'wrong-secret-value';
        $this->call('PUT', '/web/connections/'.$id, $body);
        self::assertSame(200, $this->statusCode());

        $result = $this->call('POST', '/web/connections/'.$id.'/test');
        self::assertSame(502, $this->statusCode());
        self::assertSame('target_unreachable', $result['error']);
        self::assertStringNotContainsString('wrong-secret-value', json_encode($result, \JSON_THROW_ON_ERROR));
    }

    public function testDeleteRemovesTheConnection(): void
    {
        $this->seedAndLogin();
        $id = $this->create();

        $this->call('DELETE', '/web/connections/'.$id);
        self::assertSame(204, $this->statusCode());

        $this->call('GET', '/web/connections/'.$id);
        self::assertSame(404, $this->statusCode());
    }

    public function testNamesAreUniquePerAccount(): void
    {
        $this->seedAndLogin();
        $this->create('Production');

        $body = $this->call('POST', '/web/connections', $this->body('Production'));

        self::assertSame(409, $this->statusCode());
        self::assertSame('connection_name_used', $body['error']);
    }

    public function testInvalidInputIsRefused(): void
    {
        $this->seedAndLogin();

        $engine = $this->call('POST', '/web/connections', $this->body('X', 'oracle'));
        self::assertSame(422, $this->statusCode());
        self::assertSame('invalid_input', $engine['error']);

        $port = $this->call('POST', '/web/connections', ['port' => 70000] + $this->body());
        self::assertSame(422, $this->statusCode());
        self::assertSame('invalid_input', $port['error']);

        $name = $this->call('POST', '/web/connections', ['name' => ''] + $this->body());
        self::assertSame(422, $this->statusCode());

        $missing = $this->call('POST', '/web/connections', ['name' => 'X']);
        self::assertSame(400, $this->statusCode());
        self::assertSame('bad_request', $missing['error']);
    }

    public function testAnotherAccountsConnectionIsInvisible(): void
    {
        $this->seedAndLogin('alice');
        $id = $this->create();
        $this->call('POST', '/web/logout');
        $this->seedUser('bob');
        $this->login('bob');

        foreach ([['GET', ''], ['PUT', ''], ['DELETE', ''], ['POST', '/test'], ['GET', '/tables'], ['POST', '/query']] as [$method, $suffix]) {
            $this->call($method, '/web/connections/'.$id.$suffix, 'PUT' === $method ? $this->body() : ['sql' => 'select 1']);
            self::assertSame(404, $this->statusCode(), $method.' '.$suffix);
        }
        $list = $this->call('GET', '/web/connections');
        self::assertSame([], $list['connections']);
    }

    public function testAnUnlockedKeyIsNeededToSaveAConnection(): void
    {
        $this->seedAndLogin();
        $this->client->getCookieJar()->expire('TERRILDB_UNLOCK');

        $body = $this->call('POST', '/web/connections', $this->body());

        self::assertSame(401, $this->statusCode());
        self::assertSame('locked', $body['error']);
    }

    #[DataProvider('targets')]
    public function testAConnectionCanBeTestedBeforeBeingSaved(string $envVar): void
    {
        $this->seedAndLogin();

        $result = $this->call('POST', '/web/connections/test', $this->targetBody($envVar));

        self::assertSame(200, $this->statusCode());
        self::assertTrue($result['ok']);
        self::assertIsInt($result['durationMs']);
    }

    public function testAFailedTestNeverLeaksTheParameters(): void
    {
        $this->seedAndLogin();
        $body = ['host' => 'closed.exemple.test', 'port' => 1, 'password' => 'hunter2-hunter2'] + $this->body();

        $result = $this->call('POST', '/web/connections/test', $body);

        self::assertSame(502, $this->statusCode());
        self::assertSame(['error' => 'target_unreachable'], $result);
    }

    public function testASavedConnectionCanBeTested(): void
    {
        $this->seedAndLogin();
        $created = $this->call('POST', '/web/connections', $this->targetBody('TARGET_POSTGRES_URL'));
        self::assertIsArray($created['connection']);
        self::assertIsString($created['connection']['id']);

        $result = $this->call('POST', '/web/connections/'.$created['connection']['id'].'/test');

        self::assertSame(200, $this->statusCode());
        self::assertTrue($result['ok']);
    }

    public function testSavedConnectionsSurviveAPasswordChange(): void
    {
        $this->seedAndLogin();
        $created = $this->call('POST', '/web/connections', $this->targetBody('TARGET_POSTGRES_URL'));
        $id = self::arrayOf($created['connection'])['id'];
        self::assertIsString($id);

        $this->call('POST', '/web/account/password', ['current' => self::PASSWORD, 'new' => 'a brand new passphrase']);
        self::assertSame(200, $this->statusCode());

        $this->call('POST', '/web/connections/'.$id.'/test');
        self::assertSame(200, $this->statusCode(), 'la session courante lit la connexion avec la nouvelle clé');

        $this->call('POST', '/web/logout');
        $this->login('alice', 'a brand new passphrase');
        $this->call('POST', '/web/connections/'.$id.'/test');
        self::assertSame(200, $this->statusCode(), 'une nouvelle session aussi');
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function targets(): iterable
    {
        yield from TargetDatabase::all();
    }

    public function testAConnectionCanBeColoredAndTheColorChangedOrRemoved(): void
    {
        $this->seedAndLogin();

        $created = $this->call('POST', '/web/connections', $this->body() + ['color' => 'green']);
        self::assertSame(201, $this->statusCode());
        $id = self::arrayOf($created['connection'])['id'];
        self::assertIsString($id);
        self::assertSame('green', self::arrayOf($created['connection'])['color']);

        $updated = $this->call('PUT', '/web/connections/'.$id, $this->body() + ['color' => 'blue']);
        self::assertSame('blue', self::arrayOf($updated['connection'])['color']);

        $cleared = $this->call('PUT', '/web/connections/'.$id, $this->body() + ['color' => null]);
        self::assertNull(self::arrayOf($cleared['connection'])['color']);
    }

    public function testAnUnknownColorIsRefused(): void
    {
        $this->seedAndLogin();

        $this->call('POST', '/web/connections', $this->body() + ['color' => 'hotpink']);

        self::assertSame(422, $this->statusCode());
    }

    public function testTheOwnerReordersTheirConnections(): void
    {
        $this->seedAndLogin();
        $a = $this->create('A');
        $b = $this->create('B');
        $c = $this->create('C');

        $this->call('PUT', '/web/connections/order', ['ids' => [$c, $a, $b]]);

        self::assertSame(204, $this->statusCode());
        $names = array_map(static fn ($row): mixed => self::arrayOf($row)['name'], self::arrayOf($this->call('GET', '/web/connections')['connections']));
        self::assertSame(['C', 'A', 'B'], $names);
    }

    public function testReorderingRefusesAnotherAccountsConnectionWithoutSayingItExists(): void
    {
        $this->seedAndLogin('alice');
        $theirs = $this->create('Theirs');
        $this->call('POST', '/web/logout');
        $this->seedUser('bob');
        $this->login('bob');
        $mine = $this->create('Mine');

        $body = $this->call('PUT', '/web/connections/order', ['ids' => [$mine, $theirs]]);

        self::assertSame(422, $this->statusCode());
        self::assertSame('invalid_input', $body['error']);
    }

    public function testReorderingRequiresAListOfIdentifiers(): void
    {
        $this->seedAndLogin();

        $this->call('PUT', '/web/connections/order', ['ids' => 'nope']);

        self::assertSame(400, $this->statusCode());
    }
}
