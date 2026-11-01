<?php

declare(strict_types=1);

namespace App\Tests\Integration\Driver;

use App\Core\Connection\ConnectionParameters;
use App\Core\Connection\Engine;
use App\Core\Driver\Driver;
use App\Core\Driver\Exception\TargetConnectionFailedException;
use App\Core\Driver\Exception\UnsupportedOperationException;
use App\Core\Guard\StatementKind;
use App\Core\Query\QueryOptions;
use App\Driver\Redis\RedisConnector;
use App\Driver\Redis\RedisDriver;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('integration')]
final class RedisDriverTest extends TestCase
{
    private function driver(): RedisDriver
    {
        return new RedisDriver(new RedisConnector());
    }

    public function testServesRedisOnly(): void
    {
        self::assertInstanceOf(Driver::class, $this->driver());
        self::assertSame([Engine::Redis], $this->driver()->engines());
    }

    public function testRefusesAnotherEngine(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->driver()->connect(Engine::MySql, TargetDatabase::parameters(RedisTarget::ENV));
    }

    public function testOpensAConnectionThatBrowsesKeysAndRunsCommands(): void
    {
        $seed = RedisTarget::seeded();
        try {
            $connection = $this->driver()->connect(Engine::Redis, TargetDatabase::parameters(RedisTarget::ENV));
        } catch (TargetConnectionFailedException) {
            self::markTestSkipped('The Redis target is unreachable (docker compose --profile targets up -d --wait).');
        }

        try {
            self::assertSame(Engine::Redis, $connection->engine);
            self::assertSame(9, $connection->keys()->size());
            $result = $connection->runner()->run('get user:1', StatementKind::Read, new QueryOptions());
            self::assertSame([['Alice']], $result->rows);
            $connection->close();
            $connection->close();
        } finally {
            $seed->flushdb();
        }
    }

    public function testThereIsNoTableSchema(): void
    {
        $connection = $this->driver()->connect(Engine::Redis, TargetDatabase::parameters(RedisTarget::ENV));

        try {
            $this->expectException(UnsupportedOperationException::class);
            $connection->introspector();
        } finally {
            $connection->close();
        }
    }

    public function testAWrongPasswordIsRefusedWithAGenericMessage(): void
    {
        try {
            $this->driver()->connect(Engine::Redis, TargetDatabase::parameters(RedisTarget::ENV)->withPassword('wrong-secret-value'));
            self::fail('Expected a refusal.');
        } catch (TargetConnectionFailedException $e) {
            self::assertSame('Could not connect to the database.', $e->getMessage());
        }
    }

    public function testAnUnreachableServerIsRefusedToo(): void
    {
        $parameters = TargetDatabase::parameters(RedisTarget::ENV);

        $this->expectException(TargetConnectionFailedException::class);

        $this->driver()->connect(Engine::Redis, new ConnectionParameters('127.0.0.1', 1, '0', $parameters->username, $parameters->password()));
    }

    public function testADatabaseThatIsNotANumberIsRefused(): void
    {
        $parameters = TargetDatabase::parameters(RedisTarget::ENV);

        $this->expectException(TargetConnectionFailedException::class);

        $this->driver()->connect(Engine::Redis, new ConnectionParameters($parameters->host, $parameters->port, 'shop', $parameters->username, $parameters->password()));
    }
}
