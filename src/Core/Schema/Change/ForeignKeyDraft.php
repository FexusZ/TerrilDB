<?php

declare(strict_types=1);

namespace App\Core\Schema\Change;

use App\Core\Schema\Change\Exception\InvalidSchemaChangeException;
use App\Core\Schema\ReferentialAction;

final readonly class ForeignKeyDraft
{
    /**
     * @param list<string> $columns
     * @param list<string> $referencedColumns
     */
    public function __construct(
        public ?string $originalName,
        public string $name,
        public array $columns,
        public string $referencedTable,
        public array $referencedColumns,
        public ReferentialAction $onUpdate,
        public ReferentialAction $onDelete,
    ) {
        NameRule::check($name, 'foreign key');
        NameRule::check($referencedTable, 'referenced table');
        if ([] === $columns || \count($columns) !== \count($referencedColumns)) {
            throw new InvalidSchemaChangeException('A foreign key needs as many referenced columns as columns.');
        }
        foreach ([...$columns, ...$referencedColumns] as $column) {
            NameRule::check($column, 'foreign key column');
        }
    }
}
