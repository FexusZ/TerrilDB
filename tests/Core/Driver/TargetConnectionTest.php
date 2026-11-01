<?php

declare(strict_types=1);

namespace App\Tests\Core\Driver;

use App\Core\Connection\Engine;
use App\Core\Driver\TargetConnection;
use App\Core\Schema\SchemaIntrospector;
use App\Core\Schema\TableDefinition;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;

final class TargetConnectionTest extends TestCase
{
    private function introspector(): SchemaIntrospector
    {
        return new class implements SchemaIntrospector {
            public function listTables(): array
            {
                return [];
            }

            public function describeTable(string $name): TableDefinition
            {
                throw new \LogicException('Not used in this test.');
            }
        };
    }

    public function testExposesItsEngineAndItsIntrospector(): void
    {
        $introspector = $this->introspector();
        $connection = new TargetConnection(Engine::PostgreSql, DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]), $introspector);

        self::assertSame(Engine::PostgreSql, $connection->engine);
        self::assertSame($introspector, $connection->introspector());
    }

    public function testClosingTwiceIsHarmless(): void
    {
        $dbal = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $dbal->executeQuery('SELECT 1');
        $connection = new TargetConnection(Engine::MySql, $dbal, $this->introspector());

        $connection->close();
        $connection->close();

        $this->addToAssertionCount(1);
    }
}
