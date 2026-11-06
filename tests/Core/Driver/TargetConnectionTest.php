<?php

declare(strict_types=1);

namespace App\Tests\Core\Driver;

use App\Core\Connection\Engine;
use App\Core\Driver\Exception\UnsupportedOperationException;
use App\Core\Driver\TargetConnection;
use App\Core\Guard\StatementKind;
use App\Core\KeyValue\KeyDetail;
use App\Core\KeyValue\KeyPage;
use App\Core\KeyValue\KeyValueStore;
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
            public function listDatabases(): array
            {
                return [];
            }

            public function listTablesPage(\App\Core\Schema\TablePageRequest $request): \App\Core\Schema\TablePage
            {
                return new \App\Core\Schema\TablePage([], null, 0);
            }

            public function listSchemas(): array
            {
                return [];
            }

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

    public function testAnEngineWithoutSchemaHasNoIntrospector(): void
    {
        $connection = new TargetConnection(Engine::Redis, null, $this->runner(), static function (): void {
        });

        self::assertSame(Engine::Redis, $connection->engine);
        $this->expectException(UnsupportedOperationException::class);

        $connection->introspector();
    }

    public function testKeysAreOnlyBrowsableWhereTheEngineHasThem(): void
    {
        $connection = new TargetConnection(Engine::MySql, $this->introspector(), $this->runner(), static function (): void {
        });

        $this->expectException(UnsupportedOperationException::class);

        $connection->keys();
    }

    public function testExposesTheKeyBrowserOfAKeyValueEngine(): void
    {
        $keys = new class implements KeyValueStore {
            public function scan(string $pattern, ?string $cursor, int $count): KeyPage
            {
                return new KeyPage([], null);
            }

            public function inspect(string $key, int $limit): KeyDetail
            {
                throw new \LogicException('Not used in this test.');
            }

            public function size(): int
            {
                return 0;
            }
        };
        $connection = new TargetConnection(Engine::Redis, null, $this->runner(), static function (): void {
        }, $keys);

        self::assertSame($keys, $connection->keys());
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
