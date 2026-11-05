<?php

declare(strict_types=1);

namespace App\Core\Schema;

/**
 * Une page de tables. `nextCursor` (le nom de la dernière table rendue) est nul à la fin de la liste. `total` est le nombre
 * de tables qui correspondent (lu dans le catalogue du moteur, pas dans les tables) ; il n'est calculé qu'avec la première
 * page, les suivantes le laissent nul.
 */
final readonly class TablePage
{
    /**
     * @param list<TableSummary> $tables
     */
    public function __construct(
        public array $tables,
        public ?string $nextCursor,
        public ?int $total,
    ) {
    }
}
