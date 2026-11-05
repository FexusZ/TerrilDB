<?php

declare(strict_types=1);

namespace App\Core\Schema;

use App\Core\Schema\Exception\InvalidSchemaException;

/**
 * Une base que le compte du serveur peut voir. Les bases du moteur lui-même (information_schema, system…) sont
 * signalées : l'interface les masque par défaut.
 */
final readonly class DatabaseSummary
{
    public function __construct(
        public string $name,
        public bool $system,
    ) {
        if ('' === $name) {
            throw new InvalidSchemaException('A database needs a name.');
        }
    }
}
