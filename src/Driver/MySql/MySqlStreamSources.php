<?php

declare(strict_types=1);

namespace App\Driver\MySql;

use App\Core\Connection\ConnectionParameters;
use App\Core\Connection\Engine;
use App\Core\Diagnostics\Vitess\StreamSource;
use App\Core\Diagnostics\Vitess\StreamSources;
use App\Driver\DbalConnector;
use Doctrine\DBAL\Connection;

/**
 * Vitess : les flux de `_vt.vreplication` lus sur le tablet PRIMAIRE de chaque shard du keyspace (vtgate enverrait la lecture à un seul
 * tablet). Une session par primaire, ouverte à la demande par le nom de cible du tablet et refermée aussitôt. Toute erreur (droit,
 * tablet absent, table inconnue) rend « aucun flux » : l'avertissement manque, l'instruction n'est jamais gênée.
 */
final readonly class MySqlStreamSources implements StreamSources
{
    private const MAX_PRIMARIES = 16;
    private const MAX_STREAMS = 500;

    public function __construct(
        private DbalConnector $connector,
        private Engine $engine,
        private ConnectionParameters $parameters,
        private Connection $gateway,
    ) {
    }

    public function sources(): array
    {
        $keyspace = explode('@', $this->parameters->database, 2)[0];
        $found = [];

        try {
            $primaries = 0;
            foreach ((new MySqlTabletDiagnostics($this->gateway))->tablets() as $tablet) {
                if ('PRIMARY' !== $tablet->type || ('' !== $keyspace && $tablet->keyspace !== $keyspace) || ++$primaries > self::MAX_PRIMARIES) {
                    continue;
                }
                $connection = $this->connector->connect($this->engine, $this->parameters->withDatabase($tablet->target()));
                try {
                    foreach ($connection->fetchAllNumeric('SELECT source, workflow FROM _vt.vreplication LIMIT '.self::MAX_STREAMS) as [$text, $workflow]) {
                        $source = \is_string($text) ? StreamSource::parse($text, \is_string($workflow) ? $workflow : '') : null;
                        if (null !== $source && $source->keyspace === $tablet->keyspace) {
                            $found[] = $source;
                        }
                    }
                } finally {
                    $connection->close();
                }
            }
        } catch (\Throwable) {
            return [];
        }

        return $found;
    }
}
