<?php

declare(strict_types=1);

namespace App\Driver\ClickHouse;

use App\Core\Connection\ConnectionParameters;
use App\Core\Connection\Engine;
use App\Core\Diagnostics\ServerDiagnostics;
use App\Core\Driver\Driver;
use App\Core\Driver\Exception\TargetConnectionFailedException;
use App\Core\Driver\TargetConnection;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * ClickHouse par son interface HTTP. L'ouverture vérifie tout de suite l'accès par un `SELECT 1`, pour qu'un refus
 * apparaisse à l'ouverture ; l'échec est toujours générique (le détail reste dans l'exception précédente).
 */
final readonly class ClickHouseDriver implements Driver
{
    private const CONNECT_TIMEOUT_SECONDS = 5.0;

    public function __construct(private HttpClientInterface $client)
    {
    }

    public function engines(): array
    {
        return [Engine::ClickHouse];
    }

    public function connect(Engine $engine, ConnectionParameters $parameters): TargetConnection
    {
        if (Engine::ClickHouse !== $engine) {
            throw new \InvalidArgumentException(\sprintf('This driver does not serve "%s".', $engine->value));
        }

        $http = new ClickHouseHttp($this->client, $parameters);
        try {
            $http->query('SELECT 1', timeoutSeconds: self::CONNECT_TIMEOUT_SECONDS);
        } catch (ClickHouseException $e) {
            throw TargetConnectionFailedException::create($e);
        }

        // Pas de session ni de socket à libérer : chaque requête HTTP est indépendante.
        return new TargetConnection($engine, new ClickHouseIntrospector($http, '' !== $parameters->database ? $parameters->database : 'default'), new ClickHouseQueryRunner($http), static function (): void {
        }, ddl: new ClickHouseDdlExecutor($http), data: new ClickHouseDataExecutor($http), diagnostics: new ServerDiagnostics($engine, new ClickHouseSessionDiagnostics($http), statements: new ClickHouseStatementDiagnostics($http), variables: new ClickHouseVariableDiagnostics($http)));
    }
}
