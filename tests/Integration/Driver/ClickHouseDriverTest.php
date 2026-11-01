<?php

declare(strict_types=1);

namespace App\Tests\Integration\Driver;

use App\Core\Connection\Engine;
use App\Core\Driver\Driver;
use App\Core\Driver\Exception\TargetConnectionFailedException;
use App\Core\Guard\StatementKind;
use App\Core\Query\QueryOptions;
use App\Driver\ClickHouse\ClickHouseDriver;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\HttpClient;

#[Group('integration')]
final class ClickHouseDriverTest extends TestCase
{
    private const TARGET = 'TARGET_CLICKHOUSE_URL';

    private function driver(): ClickHouseDriver
    {
        return new ClickHouseDriver(HttpClient::create());
    }

    public function testServesClickHouseOnly(): void
    {
        self::assertInstanceOf(Driver::class, $this->driver());
        self::assertSame([Engine::ClickHouse], $this->driver()->engines());
    }

    public function testRefusesAnotherEngine(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->driver()->connect(Engine::MySql, TargetDatabase::parameters(self::TARGET));
    }

    public function testOpensAConnectionThatIntrospectsAndRunsQueries(): void
    {
        try {
            $connection = $this->driver()->connect(Engine::ClickHouse, TargetDatabase::parameters(self::TARGET));
        } catch (TargetConnectionFailedException) {
            self::markTestSkipped('The ClickHouse target is unreachable (docker compose --profile targets up -d --wait).');
        }

        self::assertSame(Engine::ClickHouse, $connection->engine);
        self::assertCount(7, $connection->introspector()->listTables());
        $result = $connection->runner()->run('SELECT count() FROM orders', StatementKind::Read, new QueryOptions());
        self::assertSame([[3000]], $result->rows);
        $connection->close();
        $connection->close();
    }

    public function testAWrongPasswordIsRefusedWithAGenericMessage(): void
    {
        try {
            $this->driver()->connect(Engine::ClickHouse, TargetDatabase::parameters(self::TARGET)->withPassword('wrong-secret-value'));
            self::fail('Expected a refusal.');
        } catch (TargetConnectionFailedException $e) {
            self::assertSame('Could not connect to the database.', $e->getMessage());
        }
    }

    public function testAnUnreachableServerIsRefusedToo(): void
    {
        $parameters = TargetDatabase::parameters(self::TARGET);
        $unreachable = new \App\Core\Connection\ConnectionParameters('127.0.0.1', 1, $parameters->database, $parameters->username, $parameters->password());

        $this->expectException(TargetConnectionFailedException::class);

        $this->driver()->connect(Engine::ClickHouse, $unreachable);
    }
}
