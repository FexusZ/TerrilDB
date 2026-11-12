<?php

declare(strict_types=1);

namespace App\Core\Driver;

use App\Core\Connection\Engine;
use App\Core\Data\DataExecutor;
use App\Core\Diagnostics\ServerDiagnostics;
use App\Core\Driver\Exception\UnsupportedOperationException;
use App\Core\KeyValue\KeyValueStore;
use App\Core\Query\QueryRunner;
use App\Core\Schema\Change\DdlExecutor;
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
        private readonly ?DdlExecutor $ddl = null,
        private readonly ?DataExecutor $data = null,
        private readonly ?ServerDiagnostics $diagnostics = null,
        private readonly ?ServerVersion $version = null,
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

    /**
     * @throws UnsupportedOperationException
     */
    public function ddl(): DdlExecutor
    {
        return $this->ddl ?? throw UnsupportedOperationException::for($this->engine, 'structure');
    }

    /**
     * @throws UnsupportedOperationException
     */
    public function data(): DataExecutor
    {
        return $this->data ?? throw UnsupportedOperationException::for($this->engine, 'data');
    }

    /**
     * Lectures de supervision du serveur (sessions…), à part de la console et du garde.
     *
     * @throws UnsupportedOperationException
     */
    public function diagnostics(): ServerDiagnostics
    {
        return $this->diagnostics ?? throw UnsupportedOperationException::for($this->engine, 'diagnostics');
    }

    /**
     * La version lue à l'ouverture ; nulle si le serveur ne l'a pas donnée (ou pas de façon lisible).
     */
    public function version(): ?ServerVersion
    {
        return $this->version;
    }

    public function capabilities(): ServerCapabilities
    {
        return CapabilityMatrix::for($this->engine, $this->version);
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
