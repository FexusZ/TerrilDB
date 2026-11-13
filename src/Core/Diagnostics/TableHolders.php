<?php

declare(strict_types=1);

namespace App\Core\Diagnostics;

/**
 * Qui tient une table, avec l'état de la source : « personne » et « non lisible » ne se confondent pas.
 */
final readonly class TableHolders
{
    /**
     * @param list<TableHolder> $holders les plus anciennes transactions d'abord
     */
    public function __construct(
        public DiagnosticStatus $status,
        public array $holders,
    ) {
    }

    public static function unreadable(DiagnosticStatus $status): self
    {
        return new self($status, []);
    }
}
