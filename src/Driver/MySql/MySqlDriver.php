<?php

declare(strict_types=1);

namespace App\Driver\MySql;

use App\Core\Connection\ConnectionParameters;
use App\Core\Connection\Engine;
use App\Core\Driver\Driver;
use App\Core\Driver\TargetConnection;
use App\Driver\DbalConnector;

/**
 * MySQL et MariaDB partagent le dialecte et information_schema : un seul driver les sert.
 */
final readonly class MySqlDriver implements Driver
{
    public function __construct(private DbalConnector $connector)
    {
    }

    public function engines(): array
    {
        return [Engine::MySql, Engine::MariaDb];
    }

    public function connect(Engine $engine, ConnectionParameters $parameters): TargetConnection
    {
        if (!\in_array($engine, $this->engines(), true)) {
            throw new \InvalidArgumentException(\sprintf('This driver does not serve "%s".', $engine->value));
        }

        $connection = $this->connector->connect($engine, $parameters);

        return new TargetConnection($engine, $connection, new MySqlIntrospector($connection), new MySqlQueryRunner($connection, $engine));
    }
}
