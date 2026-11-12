<?php

declare(strict_types=1);

namespace App\Core\Driver;

use App\Core\Connection\Engine;

/**
 * Les capacités d'UN serveur : le résultat de `CapabilityMatrix::for(moteur, version)`.
 */
final readonly class ServerCapabilities
{
    /**
     * @param list<EngineCapability> $supported triées par nom
     */
    public function __construct(
        public Engine $family,
        public ?ServerVersion $version,
        private array $supported,
    ) {
    }

    public function supports(EngineCapability $capability): bool
    {
        return \in_array($capability, $this->supported, true);
    }

    /**
     * @return list<EngineCapability>
     */
    public function supported(): array
    {
        return $this->supported;
    }
}
