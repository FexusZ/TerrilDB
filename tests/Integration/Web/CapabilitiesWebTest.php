<?php

declare(strict_types=1);

namespace App\Tests\Integration\Web;

use App\Core\Driver\EngineCapability;
use App\Tests\Integration\Driver\RedisTarget;
use App\Tests\Integration\Driver\TargetDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

#[Group('integration')]
final class CapabilitiesWebTest extends WebApiTestCase
{
    private function saveTarget(string $envVar): string
    {
        $parameters = TargetDatabase::parameters($envVar);
        $created = $this->call('POST', '/web/connections', ['name' => 'Target', 'engine' => TargetDatabase::engine($envVar)->value, 'host' => $parameters->host, 'port' => $parameters->port, 'database' => $parameters->database, 'username' => $parameters->username, 'password' => $parameters->password()]);
        $id = self::arrayOf($created['connection'])['id'];
        self::assertIsString($id);

        return $id;
    }

    public function testRequiresASession(): void
    {
        $this->call('GET', '/web/connections/00000000-0000-7000-8000-000000000000/capabilities');

        self::assertSame(401, $this->statusCode());
    }

    /**
     * @return iterable<string, array{string, string, bool}>
     */
    public static function targets(): iterable
    {
        yield 'MySQL' => ['TARGET_MYSQL_URL', 'mysql', false];
        yield 'MySQL 26' => ['TARGET_MYSQL26_URL', 'mysql', false];
        yield 'MariaDB' => ['TARGET_MARIADB_URL', 'mariadb', true];
        yield 'MariaDB 13' => ['TARGET_MARIADB13_URL', 'mariadb', true];
        yield 'PostgreSQL' => ['TARGET_POSTGRES_URL', 'postgresql', true];
        yield 'ClickHouse' => ['TARGET_CLICKHOUSE_URL', 'clickhouse', false];
    }

    #[DataProvider('targets')]
    public function testTellsTheVersionAndEveryCapabilityOfTheServer(string $envVar, string $family, bool $sequences): void
    {
        $this->seedAndLogin();
        $id = $this->saveTarget($envVar);

        $body = $this->call('GET', '/web/connections/'.$id.'/capabilities');

        self::assertSame(200, $this->statusCode());
        self::assertSame($family, $body['engine']);
        $version = self::arrayOf($body['version']);
        self::assertSame(['major', 'minor', 'patch', 'flavour'], array_keys($version));
        $capabilities = self::arrayOf($body['capabilities']);
        self::assertSame(array_map(static fn (EngineCapability $c): string => $c->value, EngineCapability::cases()), array_keys($capabilities));
        self::assertSame($sequences, $capabilities['sequences']);
        self::assertTrue($capabilities['window_functions']);
    }

    public function testRedisHasAVersionAndNoCapability(): void
    {
        $this->seedAndLogin();
        $id = $this->saveTarget(RedisTarget::ENV);

        $body = $this->call('GET', '/web/connections/'.$id.'/capabilities');

        self::assertSame(200, $this->statusCode());
        self::assertSame('redis', $body['engine']);
        self::assertSame([], array_filter(self::arrayOf($body['capabilities'])));
    }

    public function testAnotherUsersConnectionIsAsUnknownAsAMissingOne(): void
    {
        $this->seedAndLogin();

        $this->call('GET', '/web/connections/00000000-0000-7000-8000-000000000000/capabilities');

        self::assertSame(404, $this->statusCode());
    }
}
