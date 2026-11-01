<?php

declare(strict_types=1);

namespace App\Core\Schema;

use App\Core\Schema\Exception\InvalidSchemaException;

final readonly class IndexDefinition
{
    /**
     * @param list<string> $columns dans l'ordre de l'index (une expression est donnée par son texte)
     */
    public function __construct(
        public string $name,
        public array $columns,
        public bool $unique,
        public bool $primary,
    ) {
        if ('' === $name || [] === $columns) {
            throw new InvalidSchemaException('An index needs a name and at least one column.');
        }
        if ($primary && !$unique) {
            throw new InvalidSchemaException('A primary key is always unique.');
        }
    }
}
