<?php

declare(strict_types=1);

namespace App\Tests\Core\Driver;

use App\Core\Connection\Engine;
use App\Core\Driver\TargetConnection;
use App\Core\Guard\StatementKind;
use App\Core\Query\QueryOptions;
use App\Core\Query\QueryResult;
use App\Core\Query\QueryRunner;
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

    private function runner(): QueryRunner
    {
        return new class implements QueryRunner {
            public function run(string $sql, StatementKind $kind, QueryOptions $options): QueryResult
            {
                return QueryResult::affected(0, 0);
            }
        };
    }

    public function testExposesItsEngineItsIntrospectorAndItsRunner(): void
    {
        $introspector = $this->introspector();
        $runner = $this->runner();
        $connection = new TargetConnection(Engine::PostgreSql, DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]), $introspector, $runner);

        self::assertSame(Engine::PostgreSql, $connection->engine);
        self::assertSame($introspector, $connection->introspector());
        self::assertSame($runner, $connection->runner());
    }

    public function testClosingTwiceIsHarmless(): void
    {
        $dbal = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $dbal->executeQuery('SELECT 1');
        $connection = new TargetConnection(Engine::MySql, $dbal, $this->introspector(), $this->runner());

        $connection->close();
        $connection->close();

        $this->addToAssertionCount(1);
    }
}
