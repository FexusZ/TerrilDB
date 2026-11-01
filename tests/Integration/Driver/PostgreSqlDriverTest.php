<?php

declare(strict_types=1);

namespace App\Tests\Integration\Driver;

use App\Core\Connection\Engine;
use App\Core\Driver\Driver;
use App\Core\Driver\Exception\TargetConnectionFailedException;
use App\Driver\DbalConnector;
use App\Driver\PostgreSql\PostgreSqlDriver;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('integration')]
final class PostgreSqlDriverTest extends TestCase
{
    public function testServesPostgreSqlOnly(): void
    {
        $driver = new PostgreSqlDriver(new DbalConnector());

        self::assertInstanceOf(Driver::class, $driver);
        self::assertSame([Engine::PostgreSql], $driver->engines());
    }

    public function testRefusesAnotherEngine(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        (new PostgreSqlDriver(new DbalConnector()))->connect(Engine::MySql, TargetDatabase::parameters('TARGET_POSTGRES_URL'));
    }

    public function testOpensAConnectionThatIntrospectsTheTarget(): void
    {
        try {
            $connection = (new PostgreSqlDriver(new DbalConnector()))->connect(Engine::PostgreSql, TargetDatabase::parameters('TARGET_POSTGRES_URL'));
        } catch (TargetConnectionFailedException) {
            self::markTestSkipped('The PostgreSQL target is unreachable (docker compose --profile targets up -d --wait).');
        }

        self::assertSame(Engine::PostgreSql, $connection->engine);
        self::assertCount(7, $connection->introspector()->listTables());
        $connection->close();
    }

    public function testAWrongPasswordIsRefused(): void
    {
        $this->expectException(TargetConnectionFailedException::class);

        (new PostgreSqlDriver(new DbalConnector()))->connect(Engine::PostgreSql, TargetDatabase::parameters('TARGET_POSTGRES_URL')->withPassword('wrong-secret-value'));
    }
}
