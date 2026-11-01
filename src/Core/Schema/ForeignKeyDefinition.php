<?php

declare(strict_types=1);

namespace App\Core\Schema;

use App\Core\Schema\Exception\InvalidSchemaException;

final readonly class ForeignKeyDefinition
{
    /**
     * @param list<string> $columns
     * @param list<string> $referencedColumns dans le même ordre que $columns
     */
    public function __construct(
        public string $name,
        public array $columns,
        public string $referencedTable,
        public array $referencedColumns,
        public ReferentialAction $onUpdate,
        public ReferentialAction $onDelete,
    ) {
        if ('' === $name || '' === $referencedTable || [] === $columns) {
            throw new InvalidSchemaException('A foreign key needs a name, columns and a referenced table.');
        }
        if (\count($columns) !== \count($referencedColumns)) {
            throw new InvalidSchemaException('A foreign key needs as many referenced columns as columns.');
        }
    }
}
