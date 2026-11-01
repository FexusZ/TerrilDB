<?php

declare(strict_types=1);

namespace App\Tests\Core\Connection;

use App\Core\Connection\Engine;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class EngineTest extends TestCase
{
    public function testStoredValuesAreStable(): void
    {
        self::assertSame('mysql', Engine::MySql->value);
        self::assertSame('mariadb', Engine::MariaDb->value);
        self::assertSame('postgresql', Engine::PostgreSql->value);
        self::assertSame('clickhouse', Engine::ClickHouse->value);
    }

    public function testDefaultPorts(): void
    {
        self::assertSame(3306, Engine::MySql->defaultPort());
        self::assertSame(3306, Engine::MariaDb->defaultPort());
        self::assertSame(5432, Engine::PostgreSql->defaultPort());
        self::assertSame(8123, Engine::ClickHouse->defaultPort());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function unknownEngines(): iterable
    {
        yield 'another engine' => ['oracle'];
        yield 'wrong case' => ['MySQL'];
        yield 'empty' => [''];
    }

    #[DataProvider('unknownEngines')]
    public function testUnknownEngineIsNotResolved(string $value): void
    {
        self::assertNull(Engine::tryFrom($value));
    }
}
