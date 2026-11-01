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
        $connection = new TargetConnection(Engine::PostgreSql, $introspector, $runner, static function (): void {
        });

        self::assertSame(Engine::PostgreSql, $connection->engine);
        self::assertSame($introspector, $connection->introspector());
        self::assertSame($runner, $connection->runner());
    }

    public function testClosingReleasesTheResourceOnlyOnce(): void
    {
        $closed = 0;
        $connection = new TargetConnection(Engine::MySql, $this->introspector(), $this->runner(), static function () use (&$closed): void {
            ++$closed;
        });

        $connection->close();
        $connection->close();

        self::assertSame(1, $closed);
    }
}
