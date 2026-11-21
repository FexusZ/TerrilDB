<?php

declare(strict_types=1);

namespace App\Tests\Integration\Web;

use App\Tests\Integration\Driver\TargetDatabase;
use PHPUnit\Framework\Attributes\Group;

#[Group('integration')]
final class ReplicaReminderWebTest extends WebApiTestCase
{
    /**
     * @return array<string, mixed>
     */
    private function mysqlBody(string $envVar = 'TARGET_MYSQL_URL'): array
    {
        $parameters = TargetDatabase::parameters($envVar);

        return ['name' => 'Cible', 'engine' => 'mysql', 'host' => $parameters->host, 'port' => $parameters->port, 'database' => $parameters->database, 'username' => $parameters->username, 'password' => $parameters->password()];
    }

    private function saveMysql(string $envVar = 'TARGET_MYSQL_URL'): string
    {
        $created = $this->call('POST', '/web/connections', $this->mysqlBody($envVar));
        self::assertSame(201, $this->statusCode());
        $id = self::arrayOf($created['connection'])['id'];
        self::assertIsString($id);

        return $id;
    }

    public function testAServerThatIsNotVitessIsNeverReminded(): void
    {
        $this->seedAndLogin();
        $id = $this->saveMysql();

        $body = $this->call('GET', '/web/connections/'.$id.'/replica');

        self::assertSame(200, $this->statusCode());
        self::assertFalse($body['available']);
    }

    public function testEnablingOnAServerThatIsNotVitessIsRefused(): void
    {
        $this->seedAndLogin();
        $id = $this->saveMysql();

        $body = $this->call('POST', '/web/connections/'.$id.'/replica/enable');

        self::assertSame(422, $this->statusCode());
        self::assertSame('replica_unavailable', $body['error']);
        self::assertSame('not_vitess', $body['reason']);
    }

    public function testPostponingIsAnswered(): void
    {
        $this->seedAndLogin();
        $id = $this->saveMysql();

        $this->call('POST', '/web/connections/'.$id.'/replica/later');

        self::assertSame(204, $this->statusCode());
    }

    public function testAnotherAccountsConnectionIsInvisible(): void
    {
        $this->seedAndLogin('alice');
        $id = $this->saveMysql();
        $this->call('POST', '/web/logout');
        $this->seedUser('bob');
        $this->login('bob');

        foreach ([['GET', '/replica'], ['POST', '/replica/enable'], ['POST', '/replica/later']] as [$method, $suffix]) {
            $this->call($method, '/web/connections/'.$id.$suffix);
            self::assertSame(404, $this->statusCode(), $method.' '.$suffix);
        }
    }

    public function testAVitessGatewayThatHasAReplicaCanBeSwitchedToReplicaReadingFromTheReminderRoute(): void
    {
        $this->seedAndLogin();
        $parameters = TargetDatabase::parameters('TARGET_VITESS_URL');
        $body = ['name' => 'Vitess', 'engine' => 'mysql', 'host' => $parameters->host, 'port' => $parameters->port, 'database' => $parameters->database, 'username' => $parameters->username, 'password' => $parameters->password()];
        $this->call('POST', '/web/connections/test', $body);
        if (200 !== $this->statusCode()) {
            self::markTestSkipped('TARGET_VITESS_URL is unreachable (docker compose --profile vitess up -d --wait target-vitess).');
        }
        $created = $this->call('POST', '/web/connections', $body);
        $id = self::arrayOf($created['connection'])['id'];
        self::assertIsString($id);

        $this->call('POST', '/web/connections/'.$id.'/replica/enable');
        self::assertSame(204, $this->statusCode());

        $shown = $this->call('GET', '/web/connections/'.$id);
        self::assertTrue(self::arrayOf($shown['parameters'])['readReplica'] ?? false);
    }
}
