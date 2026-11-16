<?php

declare(strict_types=1);

namespace App\Core\Query;

/**
 * Le résultat d'une exécution : des lignes (SELECT, SHOW, EXPLAIN, RETURNING…) ou un nombre de lignes touchées.
 * `truncated` signale que d'autres lignes existaient au-delà de la limite demandée ; `totalRows` n'est connu que
 * d'une mesure (`QueryOptions::$measure`), qui a parcouru toutes les lignes.
 */
final readonly class QueryResult
{
    /**
     * @param list<string>                           $columns
     * @param list<list<bool|float|int|string|null>> $rows
     */
    private function __construct(
        public array $columns,
        public array $rows,
        public bool $truncated,
        public ?int $affectedRows,
        public int $durationMs,
        public ?int $totalRows = null,
    ) {
    }

    /**
     * @param list<string>                           $columns
     * @param list<list<bool|float|int|string|null>> $rows
     */
    public static function rows(array $columns, array $rows, bool $truncated, int $durationMs, ?int $totalRows = null): self
    {
        return new self($columns, $rows, $truncated, null, $durationMs, $totalRows);
    }

    public static function affected(int $affectedRows, int $durationMs): self
    {
        return new self([], [], false, $affectedRows, $durationMs);
    }

    public function returnsRows(): bool
    {
        return null === $this->affectedRows;
    }

    public function rowCount(): int
    {
        return \count($this->rows);
    }
}
