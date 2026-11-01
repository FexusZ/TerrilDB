<?php

declare(strict_types=1);

namespace App\Core\Schema;

use App\Core\Schema\Exception\InvalidSchemaException;

/**
 * Une ligne de la liste des tables. Le nombre de lignes est une estimation du moteur (jamais un COUNT),
 * absente quand le moteur n'en a pas.
 */
final readonly class TableSummary
{
    public function __construct(
        public string $name,
        public TableKind $kind,
        public ?int $estimatedRows,
    ) {
        if ('' === $name) {
            throw new InvalidSchemaException('A table needs a name.');
        }
        if (null !== $estimatedRows && $estimatedRows < 0) {
            throw new InvalidSchemaException('The estimated row count cannot be negative.');
        }
    }
}
