<?php

declare(strict_types=1);

namespace App\Core\Schema;

/**
 * Les mesures d'entretien des tables, ou la raison stable pour laquelle elles n'ont pas pu être lues (`permission_denied`,
 * `unreadable`, `not_supported`) : une source illisible n'est jamais une erreur de l'audit ni un constat.
 */
final readonly class UpkeepReport
{
    /**
     * @param list<TableUpkeep> $tables
     */
    private function __construct(
        public bool $available,
        public ?string $reason,
        public array $tables,
    ) {
    }

    /**
     * @param list<TableUpkeep> $tables
     */
    public static function of(array $tables): self
    {
        return new self(true, null, $tables);
    }

    public static function unavailable(string $reason): self
    {
        return new self(false, $reason, []);
    }
}
