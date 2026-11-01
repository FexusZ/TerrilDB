<?php

declare(strict_types=1);

namespace App\Tests\Core\Driver;

use App\Core\Connection\ConnectionParameters;
use App\Core\Connection\Engine;
use App\Core\Driver\Driver;
use App\Core\Driver\DriverRegistry;
use App\Core\Driver\Exception\UnsupportedEngineException;
use App\Core\Driver\TargetConnection;
use PHPUnit\Framework\TestCase;

final class DriverRegistryTest extends TestCase
{
    /**
     * @param list<Engine> $engines
     */
    private function driver(array $engines): Driver
    {
        return new class($engines) implements Driver {
            /** @param list<Engine> $engines */
            public function __construct(private readonly array $engines)
            {
            }

            public function engines(): array
            {
                return $this->engines;
            }

            public function connect(Engine $engine, ConnectionParameters $parameters): TargetConnection
            {
                throw new \LogicException('Not used in this test.');
            }
        };
    }

    public function testFindsTheDriverServingAnEngine(): void
    {
        $mysql = $this->driver([Engine::MySql, Engine::MariaDb]);
        $postgres = $this->driver([Engine::PostgreSql]);
        $registry = new DriverRegistry([$mysql, $postgres]);

        self::assertSame($mysql, $registry->for(Engine::MySql));
        self::assertSame($mysql, $registry->for(Engine::MariaDb));
        self::assertSame($postgres, $registry->for(Engine::PostgreSql));
    }

    public function testAnEngineWithoutDriverIsRefused(): void
    {
        $registry = new DriverRegistry([$this->driver([Engine::PostgreSql])]);

        $this->expectException(UnsupportedEngineException::class);

        $registry->for(Engine::MySql);
    }

    public function testAnEmptyRegistryRefusesEveryEngine(): void
    {
        $this->expectException(UnsupportedEngineException::class);

        (new DriverRegistry([]))->for(Engine::PostgreSql);
    }

    public function testTwoDriversCannotServeTheSameEngine(): void
    {
        $this->expectException(\LogicException::class);

        new DriverRegistry([$this->driver([Engine::MySql]), $this->driver([Engine::MySql])]);
    }

    public function testAcceptsAnyIterable(): void
    {
        $driver = $this->driver([Engine::PostgreSql]);

        $registry = new DriverRegistry((static function () use ($driver) {
            yield $driver;
        })());

        self::assertSame($driver, $registry->for(Engine::PostgreSql));
    }
}
