<?php

declare(strict_types=1);

namespace App\Driver\Redis;

use App\Core\Connection\ConnectionParameters;
use App\Core\Connection\Engine;
use App\Core\Diagnostics\ServerDiagnostics;
use App\Core\Driver\Driver;
use App\Core\Driver\Exception\TargetConnectionFailedException;
use App\Core\Driver\ServerVersion;
use App\Core\Driver\TargetConnection;
use Predis\Client;
use Predis\PredisException;

/**
 * Redis : pas de schéma de tables, mais une navigation par clés (SCAN) et des commandes passées par le garde. L'ouverture
 * vérifie tout de suite l'accès par un PING (authentification et base comprises) ; l'échec est toujours générique, le
 * détail reste dans l'exception précédente.
 */
final readonly class RedisDriver implements Driver
{
    public function __construct(private RedisConnector $connector)
    {
    }

    public function engines(): array
    {
        return [Engine::Redis];
    }

    public function connect(Engine $engine, ConnectionParameters $parameters): TargetConnection
    {
        if (Engine::Redis !== $engine) {
            throw new \InvalidArgumentException(\sprintf('This driver does not serve "%s".', $engine->value));
        }

        try {
            $client = $this->connector->open($parameters);
            $client->ping();
        } catch (PredisException|\InvalidArgumentException $e) {
            throw TargetConnectionFailedException::create($e);
        }

        $connector = $this->connector;
        $version = self::version($client);

        return new TargetConnection(
            $engine,
            null,
            new RedisQueryRunner(static fn (float $readTimeout): Client => $connector->open($parameters, $readTimeout)),
            static function () use ($client): void {
                $client->disconnect();
            },
            new RedisKeyValueStore($client),
            diagnostics: new ServerDiagnostics($engine),
            version: $version,
        );
    }

    /**
     * `INFO server` : selon la version de Predis la réponse est à plat ou rangée par section.
     */
    private static function version(Client $client): ?ServerVersion
    {
        try {
            $info = $client->info('server');
        } catch (PredisException) {
            return null;
        }
        $server = \is_array($info['Server'] ?? null) ? $info['Server'] : $info;
        $text = $server['redis_version'] ?? null;

        return \is_string($text) ? ServerVersion::parse($text) : null;
    }
}
