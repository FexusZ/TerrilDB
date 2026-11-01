<?php

declare(strict_types=1);

namespace App\Core\Schema;

use App\Core\Schema\Exception\InvalidSchemaException;

/**
 * Une colonne, avec son type natif tel que le moteur l'écrit (« int unsigned », « character varying(20) »).
 */
final readonly class ColumnDefinition
{
    public function __construct(
        public string $name,
        public string $type,
        public bool $nullable,
        public ?string $default,
        public bool $autoIncrement,
        public ?string $comment,
    ) {
        if ('' === $name || '' === $type) {
            throw new InvalidSchemaException('A column needs a name and a type.');
        }
    }
}
