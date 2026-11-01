<?php

declare(strict_types=1);

namespace App\Tests\Integration\Driver;

use App\Core\Connection\Engine;
use App\Core\Driver\Driver;
use App\Core\Driver\Exception\TargetConnectionFailedException;
use App\Driver\DbalConnector;
use App\Driver\MySql\MySqlDriver;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('integration')]
final class MySqlDriverTest extends TestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function targets(): iterable
    {
        yield 'MySQL' => ['TARGET_MYSQL_URL'];
        yield 'MariaDB' => ['TARGET_MARIADB_URL'];
    }

    public function testServesMySqlAndMariaDb(): void
    {
        $driver = new MySqlDriver(new DbalConnector());

        self::assertInstanceOf(Driver::class, $driver);
        self::assertSame([Engine::MySql, Engine::MariaDb], $driver->engines());
    }

    public function testRefusesAnotherEngine(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        (new MySqlDriver(new DbalConnector()))->connect(Engine::PostgreSql, TargetDatabase::parameters('TARGET_MYSQL_URL'));
    }

    #[DataProvider('targets')]
    public function testOpensAConnectionThatIntrospectsTheTarget(string $envVar): void
    {
        $driver = new MySqlDriver(new DbalConnector());
        $engine = TargetDatabase::engine($envVar);

        try {
            $connection = $driver->connect($engine, TargetDatabase::parameters($envVar));
        } catch (TargetConnectionFailedException) {
            self::markTestSkipped(\sprintf('%s is unreachable (docker compose --profile targets up -d --wait).', $envVar));
        }

        self::assertSame($engine, $connection->engine);
        self::assertCount(7, $connection->introspector()->listTables());
        $connection->close();
    }

    #[DataProvider('targets')]
    public function testAWrongPasswordIsRefused(string $envVar): void
    {
        $this->expectException(TargetConnectionFailedException::class);

        (new MySqlDriver(new DbalConnector()))->connect(TargetDatabase::engine($envVar), TargetDatabase::parameters($envVar)->withPassword('wrong-secret-value'));
    }
}
