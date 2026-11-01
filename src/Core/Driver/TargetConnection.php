<?php

declare(strict_types=1);

namespace App\Core\Driver;

use App\Core\Connection\Engine;
use App\Core\Schema\SchemaIntrospector;
use Doctrine\DBAL\Connection;

/**
 * Une connexion ouverte vers une base cible. Elle se ferme explicitement : les identifiants ne doivent pas
 * survivre à l'usage qui en est fait.
 */
final readonly class TargetConnection
{
    public function __construct(
        public Engine $engine,
        private Connection $connection,
        private SchemaIntrospector $introspector,
    ) {
    }

    public function introspector(): SchemaIntrospector
    {
        return $this->introspector;
    }

    public function close(): void
    {
        $this->connection->close();
    }
}
