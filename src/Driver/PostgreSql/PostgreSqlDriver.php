<?php

declare(strict_types=1);

namespace App\Driver\PostgreSql;

use App\Core\Connection\ConnectionParameters;
use App\Core\Connection\Engine;
use App\Core\Diagnostics\ServerDiagnostics;
use App\Core\Driver\Driver;
use App\Core\Driver\ServerVersion;
use App\Core\Driver\TargetConnection;
use App\Driver\DbalConnector;
use App\Driver\DbalDataExecutor;
use App\Driver\DbalDdlExecutor;
use Doctrine\DBAL\Connection;

final readonly class PostgreSqlDriver implements Driver
{
    public function __construct(private DbalConnector $connector)
    {
    }

    public function engines(): array
    {
        return [Engine::PostgreSql];
    }

    public function connect(Engine $engine, ConnectionParameters $parameters): TargetConnection
    {
        if (Engine::PostgreSql !== $engine) {
            throw new \InvalidArgumentException(\sprintf('This driver does not serve "%s".', $engine->value));
        }

        $connection = $this->connector->connect($engine, $parameters);
        $version = self::version($connection);

        return new TargetConnection($engine, new PostgreSqlIntrospector($connection), new PostgreSqlQueryRunner($connection), $connection->close(...), ddl: new DbalDdlExecutor($connection, $engine), data: new DbalDataExecutor($connection, $engine), diagnostics: new ServerDiagnostics($engine, new PostgreSqlSessionDiagnostics($connection), new PostgreSqlLockDiagnostics($connection)), version: $version);
    }

    /**
     * Lue une fois à l'ouverture ; une version illisible ne fait pas échouer la connexion.
     */
    private static function version(Connection $connection): ?ServerVersion
    {
        try {
            $text = $connection->fetchOne('SHOW server_version');
        } catch (\Throwable) {
            return null;
        }

        return \is_string($text) ? ServerVersion::parse($text) : null;
    }
}
