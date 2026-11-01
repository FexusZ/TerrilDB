<?php

declare(strict_types=1);

namespace App\Core\Driver;

use App\Core\Connection\Engine;
use App\Core\Driver\Exception\UnsupportedOperationException;
use App\Core\KeyValue\KeyValueStore;
use App\Core\Query\QueryRunner;
use App\Core\Schema\SchemaIntrospector;

/**
 * Une connexion ouverte vers une base cible. Elle se ferme explicitement : les identifiants ne doivent pas
 * survivre à l'usage qui en est fait. La ressource sous-jacente (DBAL, HTTP…) reste celle du driver, qui
 * fournit la façon de la libérer. Selon le moteur, une connexion offre un schéma de tables (SQL), une navigation par
 * clés (Redis), et toujours un exécuteur d'instructions ; ce qui manque lève UnsupportedOperationException.
 */
final class TargetConnection
{
    private ?\Closure $release;

    /**
     * @param \Closure(): void $release
     */
    public function __construct(
        public readonly Engine $engine,
        private readonly ?SchemaIntrospector $introspector,
        private readonly QueryRunner $runner,
        \Closure $release,
        private readonly ?KeyValueStore $keys = null,
    ) {
        $this->release = $release;
    }

    /**
     * @throws UnsupportedOperationException
     */
    public function introspector(): SchemaIntrospector
    {
        return $this->introspector ?? throw UnsupportedOperationException::for($this->engine, 'schema');
    }

    /**
     * @throws UnsupportedOperationException
     */
    public function keys(): KeyValueStore
    {
        return $this->keys ?? throw UnsupportedOperationException::for($this->engine, 'keys');
    }

    public function runner(): QueryRunner
    {
        return $this->runner;
    }

    public function close(): void
    {
        $release = $this->release;
        $this->release = null;
        $release?->__invoke();
    }
}
