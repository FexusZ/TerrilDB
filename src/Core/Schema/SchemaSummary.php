<?php

declare(strict_types=1);

namespace App\Core\Schema;

use App\Core\Schema\Exception\InvalidSchemaException;

/**
 * Un schéma d'une base PostgreSQL (le niveau entre la base et les tables). Ceux du moteur (pg_catalog,
 * information_schema) sont signalés : l'interface les masque par défaut.
 */
final readonly class SchemaSummary
{
    public function __construct(
        public string $name,
        public bool $system,
    ) {
        if ('' === $name) {
            throw new InvalidSchemaException('A schema needs a name.');
        }
    }
}
