<?php

declare(strict_types=1);

namespace App\Core\Data;

/**
 * Ce qu'il faudrait exécuter. `transactional` : le moteur annule tout en cas d'échec. `asynchronous` : le moteur applique les
 * modifications et suppressions plus tard (mutations ClickHouse), elles ne se voient donc pas tout de suite.
 */
final readonly class DataPlan
{
    /**
     * @param list<DataStatement> $statements
     */
    public function __construct(public array $statements, public bool $transactional, public bool $asynchronous)
    {
    }

    public function hasDestructive(): bool
    {
        foreach ($this->statements as $statement) {
            if ($statement->destructive) {
                return true;
            }
        }

        return false;
    }
}
